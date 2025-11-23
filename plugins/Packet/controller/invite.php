<?php

namespace handlers;

use callHandler;
use DialogManager;
use Plugin\Utils\cli;
use plugin\Utils\network;
use ObjectProxy;
use plugins\Utils\cache;
use sip;
use Swoole\Coroutine;
use Swoole\Timer;

class invite
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        if (array_key_exists($info['address'], cache::global()['bannedIps'])) return false;

        $headers = $data['headers'];

        $callId = $headers['Call-ID'][0];
        $via = $headers['Via'][0];

        // Tratamento do rport para NAT traversal no INVITE
        if (str_contains($via, 'rport')) {
            // Extrair informações do Via original
            $viaParams = [];
            if (preg_match('/SIP\/2\.0\/UDP\s+([^;]+)(.*)/i', $via, $matches)) {
                $hostPort = $matches[1];
                $params = $matches[2];

                // Se rport está presente mas sem valor, adicionar a porta real
                if (preg_match('/rport(?!=)/i', $params)) {
                    $params = preg_replace('/rport(?!=)/i', 'rport=' . $info['port'], $params);
                }

                // Se não tem received, adicionar o IP real de onde recebemos
                if (!str_contains($params, 'received')) {
                    $params .= ';received=' . $info['address'];
                }

                // Reconstituir o cabeçalho Via
                $headers['Via'][0] = 'SIP/2.0/UDP ' . $hostPort . $params;
                $data['headers']['Via'][0] = $headers['Via'][0];
            }
        }


        $backupHeaders = $headers;
        if (empty($headers['To'][0]) || empty($headers['From'][0])) return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        $data['headers']['Allow'] = ['INVITE, ACK, CANCEL, BYE, OPTIONS, REFER, NOTIFY'];
        $uri = sip::extractURI($headers['To'][0]);
        $uriFrom = sip::extractURI($headers['From'][0]);
        $extension = $uriFrom['user'];
        // se o from for anonimo, usar o contact
        if (str_contains($uriFrom['user'], 'anonymous')) {
            $uriFrom = sip::extractURI($headers['Contact'][0]);
            $extension = $uriFrom['user'];
        }
        if (sip::findUsernameByAddress($info['address'])) {
            $n = sip::findUsernameByAddress($info['address']);
            $extension = $n['u'];

        }



        if (sip::findUsername($uri['user'])) {
            cli::pcl("Chamada amiga");
            return call_user_func('\trunk\friendInvite::resolve', $socket, $data, $info);
        }
        if (\trunk::userIsTrunked($extension)) {
            if (method_exists('\trunk\invite', 'resolve')) {
                return call_user_func('\trunk\invite::resolve', $socket, $data, $info);
            } else
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }


        $socket->sendto($info['address'], $info['port'], renderMessages::respond100Trying($headers));
        $getByTrunkFrom = sip::getTrunkByUserFromDatabase($uriFrom['user']);
        $findConnection = cache::findConnection($uri['user']);

        if (!$findConnection) {
            $findConnection = sip::getTrunkByUserFromDatabase($uriFrom['user']);
            if (!$findConnection) {


                $bannedIps = cache::global()['bannedIps'];
                $ip = $info['address'];
                $bannedIps[$ip] = ['expires' => time() + 120, 'sip' => sip::renderSolution($data)];
                file_put_contents('banned.json', json_encode($bannedIps));
                cache::define('bannedIps', $bannedIps);


                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers, 'Credencial inválida! ou usuário está sem um tronco vinculado.'));
            } else {
                $findConnection = ['address' => $findConnection['trunk']['h'], 'port' => 5060];
            }
        }
        $uriUser = $uri['user'];
        if (sip::getTrunkByUserFromDatabase($uriUser)) {
            $details = sip::getTrunkByUserFromDatabase($uriUser);
            if ($details['trunk']['r'] === false) {
                print cli::color('bold_red', "Invite de $info[address]:$info[port] -> $info[server_socket] interrompido pois conhece") . PHP_EOL;

                $tag = bin2hex(random_bytes(10));
                $baseHeaders = [
                    'Via' => $headers['Via'],
                    'Max-Forwards' => ['70'],
                    'From' => $headers['From'],
                    'To' => [
                        sip::renderURI([
                            'user' => $uri['user'],
                            'peer' => ['host' => $socket->host],
                            'additional' => ['tag' => $tag]
                        ])
                    ],
                    'Call-ID' => $headers['Call-ID'],
                    'CSeq' => $headers['CSeq'],
                    'Contact' => [sip::renderURI([sip::renderURI(['user' => $uri['user'], 'peer' => ['host' => $socket->host]])])],
                ];


                $tryModel = renderMessages::respond100Trying($baseHeaders);
                $socket->sendto($info['address'], $info['port'], $tryModel, $info['server_socket']);
                $modelRing = [
                    'method' => '180',
                    'methodForParser' => "SIP/2.0 180 Ringing",
                    'headers' => [
                        'Via' => $baseHeaders['Via'],
                        'Max-Forwards' => ['70'],
                        'From' => $baseHeaders['From'],
                        'To' => [
                            sip::renderURI([
                                'user' => $uri['user'],
                                'peer' => ['host' => $socket->host],
                                'additional' => ['tag' => bin2hex(random_bytes(10))],
                            ])
                        ],
                        'Call-ID' => $headers['Call-ID'],
                        'CSeq' => $headers['CSeq'],
                        'Contact' => [sip::renderURI([
                            'user' => $uriFrom['user'],
                            'peer' => ['host' => $socket->host]
                        ])]
                    ]
                ];
                $socket->sendto($info['address'], $info['port'], sip::renderSolution($modelRing), $info['server_socket']);
                $localIp = network::getLocalIp();
                $peerProxyPort = network::getFreePort();
                $receiveIp = $info['address'];
                $receivePort = explode(' ', $data['sdp']['m'][0])[1];

                $renderOK = [
                    'method' => '200',
                    'methodForParser' => "SIP/2.0 200 OK",
                    'headers' => [
                        'Via' => $headers['Via'],
                        'Call-ID' => $modelRing['headers']['Call-ID'],
                        'Max-Forwards' => ['70'],
                        'From' => $modelRing['headers']['From'],
                        'To' => $modelRing['headers']['To'],
                        'CSeq' => $modelRing['headers']['CSeq'],
                        'Allow' => ['INVITE, ACK, CANCEL, BYE, OPTIONS, REFER, NOTIFY'],
                        'Supported' => ['replaces, gruu'],
                        'Server' => [cache::global()['interface']['server']['serverName']],
                        'Contact' => [sip::renderURI(['user' => $uri['user'], 'peer' => ['host' => $socket->host]])],
                        'Content-Type' => ['application/sdp'],
                    ],
                    'sdp' => [
                        'v' => ['0'],
                        'o' => ["$uri[user] 0 0 IN IP4 $localIp"],
                        's' => [cache::global()['interface']['server']['serverName']],
                        'c' => ["IN IP4 $localIp"],
                        't' => ['0 0'],
                        'm' => ["audio $peerProxyPort RTP/AVP 0 8 101"],
                        'a' => [
                            'rtpmap:0 PCMU/8000',
                            'rtpmap:8 PCMA/8000',
                            'rtpmap:101 telephone-event/8000',
                            'fmtp:101 0-15',
                            'sendrecv',
                        ],
                    ]
                ];
                $socket->sendto($info['address'], $info['port'], sip::renderSolution($renderOK), $info['server_socket']);


                /** @var ObjectProxy $sop */
                $sop = cache::global()['swooleObjectProxy'];
                if (!$sop->isset($callId)) {
                    $sop->set($callId, new \callHandler());


                    /** @var callHandler $callHandler */
                    $callHandler = $sop->get($callId);
                    $callHandler::staticSendAudio($callId, '/home/lotus/PROJETOS/0Apbxs/df21_converted.wav', $receiveIp, $receivePort);
                    $callHandler->startMedia($peerProxyPort);

                    $callHandler->registerDtmfCallback('3', function () use ($callId, $callHandler, $receiveIp, $receivePort) {
                        $callHandler->mute();
                        Coroutine::sleep(1);
                        $callHandler->unmute();
                        $callHandler::staticSendAudio($callId, '/home/lotus/PROJETOS/0Apbxs/d27_converted.wav', $receiveIp, $receivePort);
                        $cpf = '';
                        for ($i = 0; $i < 9; $i++) {
                            $callHandler->registerDtmfCallback($i, function () use ($callId, $callHandler, &$cpf, $i, $receiveIp, $receivePort) {
                                $cpf .= $i;
                                if (strlen($cpf) == 11) {
                                    print cli::color('yellow', 'CPF: ' . $cpf) . PHP_EOL;
                                    $callHandler::staticSendAudio($callId, '/home/lotus/PROJETOS/0Apbxs/df21_converted.wav', $receiveIp, $receivePort);
                                }
                            });
                        }
                    });


                } else {
                    print cli::color('yellow', 'nao envia audio agora') . PHP_EOL;
                }
                return true;
            }
        }


        $freePort = network::getFreePort();
        $mediaIp = $info['address'];
        $mediaPort = (int)value($data["sdp"]["m"][0], "audio ", " ");
        $localIp = network::getLocalIp();


        $data['sdp']['c'][0] = "IN IP4 $localIp";
        $data['sdp']['m'][0] = str_replace($mediaPort, $freePort, $data['sdp']['m'][0]);

        foreach ($data["sdp"] as $key => $values) {
            foreach ($data["sdp"][$key] as $k => $v) {
                $getIpx = trim(value($v, "IN IP4 ", PHP_EOL));
                if (empty($getIpx)) $getIpx = trim(value($v, "IN IP6 ", PHP_EOL));
                if (!str_contains($v, $localIp)) {
                    $data["sdp"][$key][$k] = str_replace($getIpx, $localIp, $v);
                    if (str_contains($v, 'sendonly')) {
                        return false;
                    }
                }
            }
        }


        $refer = false;
        if (count($data['headers']['Via']) > 1) {
            if (str_contains($data['headers']['Via'][1], ';refer')) {
                $refer = true;
                unset($data['headers']['Via'][1]);
            }
        }
        $data['sdp'] = [
            'v' => ['0'],
            'o' => ["root " . rand(11111, 99999) . " " . rand(11111, 99999) . " IN IP4 $localIp"],
            's' => [cache::global()['interface']['server']['serverName']],
            'c' => ["IN IP4 $localIp"],
            't' => ['0 0'],
            'm' => ["audio $freePort RTP/AVP 0 8 101"],
            'a' => [
                'rtpmap:0 PCMU/8000',
                'rtpmap:8 PCMA/8000',
                'rtpmap:101 telephone-event/8000',
                'fmtp:101 0-15',
                'sendrecv',
            ],
        ];


        $data['headers']['Via'][] = "SIP/2.0/UDP {$info['address']}:{$info['port']};branch=z9hG4bK" . uniqid() . (!$refer ? '' : ';refer') . ";extension=$extension";

        $contactURI = sip::extractURI($headers['Contact'][0]);
        $contactURI['peer']['port'] = 5060;
        $contactURI['peer']['host'] = $localIp;
        $data['headers']['Contact'][0] = sip::renderURI($contactURI);
        $callId = trim($headers['Call-ID'][0]);
        $dialogProxy = cache::global()['dialogProxy'];
        if (!is_array($dialogProxy)) $dialogProxy = [];
        if (!array_key_exists($callId, $dialogProxy)) $dialogProxy[$callId] = [];


        if (!empty($headers['Authorization'])) {
            $renderCancelHeaders = [
                "Via" => $data['headers']['Via'], // Reutilizar a mesma Via do INVITE
                "Max-Forwards" => !empty($data['headers']['Max-Forwards']) ?? ['70'],
                "From" => $data['headers']['From'], // O mesmo cabeçalho From do INVITE
                "To" => $data['headers']['To'], // O mesmo cabeçalho To do INVITE (com o mesmo tag)
                "Call-ID" => $data['headers']['Call-ID'], // Deve ser idêntico ao do INVITE
                "Content-Length" => [0], // ACK normalmente não contém corpo
                "CSeq" => $data['headers']['CSeq'],
            ];

            Timer::after(60000, function () use ($callId, $socket, $data, $info, $findConnection, $renderCancelHeaders) {
                $dialogProxy = cache::global()['dialogProxy'];
                if (is_array($dialogProxy)) {
                    if (array_key_exists($callId, $dialogProxy)) {
                        if (is_array($dialogProxy[$callId])) {
                            if (count($dialogProxy[$callId]) < 2) {
                                $invite = sip::extractURI($data['headers']['To'][0])['user'];
                                $cancelHeaders = [
                                    "method" => "CANCEL",
                                    "methodForParser" => "CANCEL sip:{$invite}@{$findConnection['address']}:{$findConnection['port']} SIP/2.0",
                                    "headers" => $renderCancelHeaders,
                                ];
                                $render = sip::renderSolution($cancelHeaders);
                                $socket->sendto($findConnection['address'], $findConnection['port'], $render, $info['server_socket']);
                                cache::persistExpungeCall($callId, "277");
                            }
                        }
                    }
                }
            });


            $dialogProxy[$callId][$uriFrom['user']] = [
                'proxyPort' => $freePort,
                'proxyIp' => $localIp,
                'peerPort' => $mediaPort,
                'peerIp' => $mediaIp,
                'startBy' => true,
                'startedAt' => time(),
                'username' => $uriFrom['user'],
                'preCancel' => [
                    'address' => $findConnection['address'],
                    'port' => $findConnection['port'],
                    'render' => sip::renderSolution([
                        "method" => "CANCEL",
                        "methodForParser" => "CANCEL sip:" . sip::extractURI($data['headers']['To'][0])['user'] . "@{$findConnection['address']}:{$findConnection['port']} SIP/2.0",
                        "headers" => $renderCancelHeaders,
                    ])
                ]
            ];
            cache::define('dialogProxy', $dialogProxy);


            $token = sip::security($data, $info);
            if (!$token) {
                print cli::color('bold_red', "$info[address]:$info[port] Autenticação inválida!") . PHP_EOL;
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
            }
            $currentSecond = date('s');
            $lastSecond = cache::get('lastSecond');

            // Se não existir lastSecond, inicializa-o junto com o contador
            if ($lastSecond === null) {
                cache::define('lastSecond', $currentSecond);
                cache::define('callsLastSecond', 0);
                $lastSecond = $currentSecond;
            }

            // Se o segundo atual for diferente do armazenado, reinicia o contador
            if ($currentSecond !== $lastSecond) {
                cache::define('callsLastSecond', 0);
                cache::define('lastSecond', $currentSecond);
            }

            cache::increment('totalCalls');
            cache::increment('callsLastSecond');
            $cps = cache::get('callsLastSecond');
            $maxCps = cache::get('cpsMax');

            if ($cps > $maxCps) {
                print cli::cl('red', "calls last second: $cps > max cps: $maxCps");
                cache::define('cpsMax', $cps);
            }
            $data['headers']['Authorization'][0] = $token;


            $clone = $uriFrom;
            $c = $getByTrunkFrom['account']['c'];
            if (array_key_exists('bi', $getByTrunkFrom['account'])) {
                if ($getByTrunkFrom['account']['bi'] === 'inactive') $c = $getByTrunkFrom['account']['c'];
                else $c = sip::loadBestCallerId(sip::extractURI($headers['To'][0])['user']);
            } else {
                $c = $getByTrunkFrom['account']['c'];
            }
            print "O novo caller id será $c" . PHP_EOL;
            $clone['user'] = $c;
            $cloneFrom = sip::renderURI($clone);
            $data['headers']['From'][0] = $cloneFrom;

            $callId = $headers['Call-ID'][0];
            if (!empty($headers['Authorization'][0])) {
                $auths = cache::global()['auth'];
                $auths[$callId] = $headers['Authorization'][0];
                cache::define('auth', $auths);
            }
        }
        $toUser = sip::getTrunkByUserFromDatabase($uri['user']);
        if (!$toUser) {
            print cli::color('blue', "Chamada externa para $uriFrom[user]") . PHP_EOL;
            $findFrom = sip::getTrunkByUserFromDatabase($uriFrom['user']);
            $findConnection = [
                'address' => $findFrom['trunk']['h'],
                'port' => 5060,
            ];
        } else {
            if (empty($headers['Authorization'])) {
                $authHeaders = [
                    "method" => "401",
                    "methodForParser" => "SIP/2.0 401 Unauthorized",
                    "headers" => [
                        "Via" => $headers['Via'],
                        "Max-Forwards" => !empty($headers['Max-Forwards']) ?? ['70'],
                        "From" => $headers['From'],
                        "To" => $headers['To'],
                        "Call-ID" => $headers['Call-ID'],
                        "Content-Length" => [0],
                        "WWW-Authenticate" => ['Digest realm="asterisk", nonce="' . md5(time()) . '", algorithm=MD5'],
                        'CSeq' => $headers['CSeq'],
                    ],
                ];
                $render = sip::renderSolution($authHeaders);
                print cli::color('bold_red', "Invite de $info[address]:$info[port] -> $info[server_socket] interrompido!") . PHP_EOL;
                return $socket->sendto($info['address'], $info['port'], $render, $info['server_socket']);
            }
        }

        $render = sip::renderSolution($data);
        return $socket->sendto($findConnection['address'], $findConnection['port'], $render, $info['server_socket']);
    }
}
