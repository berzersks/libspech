<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;
use Swoole\Timer;

class register
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $ipReceive = $info['address'];
        $findData = sip::findUsernameByAddress($ipReceive);
        if ($findData) {
            $n = $findData;
            $extension = $n['u'];
            $connections = cache::getConnections();
            $parseContact = sip::extractURI($data['headers']['Contact'][0]);
            if (!array_key_exists('User-Agent', $headers)) $headers['User-Agent'][0] = 'null';
            if (!array_key_exists('expires', $parseContact['additional'])) $parseContact['additional']['expires'] = 3600;
            $connections[$extension] = [
                'address' => $info['address'],
                'port' => $info['port'],
                'userAgent' => $headers['User-Agent'][0],
                'timestamp' => time(),
                'expires' => $parseContact['additional']['expires'],
            ];
            if ($parseContact['additional']['expires'] < 2) $parseContact['additional']['expires'] = 2;
            Timer::after($parseContact['additional']['expires'] * 1000, function () use ($extension) {
                $connections = cache::getConnections();
                unset($connections[$extension]);
                cache::updateConnections($connections);
            });
            cache::updateConnections($connections);


            if (empty($parseContact)) {
                $parseContact = sip::extractURI($headers['Contact'][0]);
                $parseContact['additional']['expires'] = !empty($headers['Expires']) ? $headers['Expires'][0] : 120;
            }
            $uriFrom = sip::extractURI($headers['From'][0]);
            $uriFrom['user'] = $extension;
            $headers['From'][0] = sip::renderURI($uriFrom);
            $modelOK = [
                "method" => "200",
                "methodForParser" => "SIP/2.0 200 OK",
                "headers" => [
                    "Via" => $headers['Via'],
                    "Max-Forwards" => !empty($headers['Max-Forwards']) ?? ['70'],
                    "From" => [
                        sip::renderURI([
                            'user' => $extension,
                            'peer' => [
                                'host' => network::getLocalIp(),
                                'port' => '5060',
                                'extra' => false
                            ],
                            'additional' => $uriFrom['additional']
                        ])
                    ],
                    "To" => $headers['To'],
                    "Call-ID" => $headers['Call-ID'],
                    "Content-Length" => [0],
                    "CSeq" => $headers['CSeq'],
                    "Server" => [cache::global()['interface']['server']['serverName']],
                    "Allow" => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE, INFO'],
                    'Supported' => ['replaces, 100rel, timer'],
                    'Expires' => [$parseContact['additional']['expires']],
                    'Contact' => [$data['headers']['Contact'][0]],
                    'Date' => [date('D, d M Y H:i:s T')],
                ],
            ];
            $render = sip::renderSolution($modelOK);
            return $socket->sendto($info['address'], $info['port'], $render, $info['server_socket']);

        }

        // Tratamento do rport para NAT traversal
        $via = $headers['Via'][0];
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
            }
        }

        $uri = sip::extractURI($headers['To'][0]);
        $uriFromBackup = sip::extractURI($headers['From'][0]);
        if (array_key_exists($info['address'], cache::global()['bannedIps'])) return false;
        if (!array_key_exists('To', $headers) || empty($headers['To'][0])) {
            print cli::color('bold_red', "Deny fromx1 $info[address]:$info[port]") . PHP_EOL;
            $bannedIps = cache::global()['bannedIps'];
            $ip = $info['address'];
            $bannedIps[$ip] = ['expires' => time() + 120, 'sip' => 'unknown', 'reason' => sip::renderSolution($data)];
            file_put_contents('banned.json', json_encode($bannedIps));
            cache::define('bannedIps', $bannedIps);
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }

        if (array_key_exists('Expires', $headers)) {
            $parseContact = sip::extractURI($headers['Contact'][0]);
            $parseContact['additional']['expires'] = $headers['Expires'][0];
            $headers['Contact'][0] = sip::renderURI($parseContact);
            $data['headers']['Contact'][0] = sip::renderURI($parseContact);
        }


        if (!isset($headers['Content-Length'][0])) {
            print cli::color('bold_red', "Deny fromx2 $info[address]:$info[port]") . PHP_EOL;
            $bannedIps = cache::global()['bannedIps'];
            $ip = $info['address'];
            $bannedIps[$ip] = ['expires' => time() + 120, 'sip' => 'unknown', 'reason' => sip::renderSolution($data)];
            file_put_contents('banned.json', json_encode($bannedIps));
            cache::define('bannedIps', $bannedIps);
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }

        $uriBackup = $uri;
        $getByTrunk = sip::getTrunkByUserFromDatabase($uri['user']);
        if (!$getByTrunk) {
            $found = false;
            $userFound = false;
            $database = cache::get('database');
            //var_dump($database);
            foreach ($database as $userData) {
                if ($userData['u'] == $uri['user']) {
                    $userFound = $userData;
                    $found = true;
                }
            }
            if ($found) {
                if (array_key_exists('Authorization', $headers)) {
                    $trunkData = [
                        'register' => false,
                        'username' => $uri['user'],
                        'address' => network::getLocalIp(),
                        'password' => $userFound['p'],
                        'host' => network::getLocalIp(),
                    ];
                } else {
                    $trunkData = [
                        'register' => false,
                        'username' => false,
                        'address' => false,
                        'password' => false,
                        'host' => false,
                    ];
                }
            } else {
            return false;
                $startFloodTime = time();
                Timer::tick(10, function ($timerId) use ($socket, $info, $uriFromBackup, $ipReceive, $startFloodTime) {
                    $floodTimeReciphroc = 60;
                    // pausar quando atingir
                    if (time() - $startFloodTime > $floodTimeReciphroc) {
                        Timer::clear($timerId);
                        cli::pcl("FLOOD FINALIZADO PARA ARROMBADO!");
                    }
                    if (array_key_exists($ipReceive, cache::global()['bannedIps'])) return false;
                    // Montar MESSAGE SIP

                    $smsVoipModel = [
                        "method" => "MESSAGE",
                        "methodForParser" => "MESSAGE sip:$uriFromBackup[user]@{$info['address']}:{$info['port']} SIP/2.0",
                        "headers" => [
                            "Via" => ["SIP/2.0/UDP " . $info['address'] . ":" . $info['port'] . ";branch=z9hG4bK" . uniqid()],
                            "Max-Forwards" => ["70"],
                            "To" => [
                                sip::renderURI([
                                    "user" => $uriFromBackup["user"],
                                    "peer" => [
                                        "host" => $info["address"],
                                        "port" => $info["port"],
                                    ],
                                ]),
                            ],
                            "From" => [
                                sip::renderURI([
                                    "user" => "please-remove-me",
                                    "peer" => [
                                        "host" => cache::get('myIpAddress'),
                                        "port" => 5060 + rand(1, 100),
                                    ],
                                    "additional" => [
                                        "tag" => uniqid(time()),
                                    ],
                                ]),
                            ],
                            "Call-ID" => [md5(time() . $uriFromBackup['user'])],
                            "CSeq" => ["1 MESSAGE"],
                            "User-Agent" => [cache::global()['interface']['server']['serverName'] ?? "SpechShop-SIP"],
                            "Content-Type" => ["text/plain"],
                        ],
                        "body" => 'Pedimos que remova as tentativas de registros em nossa plataforma caso não possua acesso, casso acredite ser um engano, contato-nos imediatamente.',
                    ];

                    $sipMessage = \sip::renderSolution($smsVoipModel);
                    //$socket->sendto($info['address'], $info['port'], $sipMessage, $info['server_socket']);
                });

                return false;
            }
        } else {
            $trunkData = [
                'username' => $getByTrunk['trunk']['u'],
                'password' => $getByTrunk['trunk']['p'],
                'address' => $getByTrunk['trunk']['h'],
                'register' => $getByTrunk['trunk']['r'],
            ];
        }

        $uriFrom = sip::extractURI($headers['From'][0]);
        $uriFrom['user'] = $trunkData['username'];
        $uri['user'] = $trunkData['username'];
        $uriFromRender = sip::renderURI($uriFrom);
        $uriToRender = sip::renderURI($uri);


        if (!empty($headers['Authorization'])) {
            $token = sip::security($data, $info);
            if (!$token) {
                $nonce = value($headers['Authorization'][0], 'nonce="', '"');
                $realResponse = sip::generateResponse(
                    $trunkData['username'],
                    cache::global()['interface']['server']['serverName'],
                    $trunkData['password'],
                    $nonce,
                    value($headers['Authorization'][0], 'uri="', '"'),
                    $data['method'],
                );

                $response = value($headers['Authorization'][0], 'response="', '"');
                if ($response != $realResponse) {
                    print cli::color('bold_red', "Deny fromx4 $info[address]:$info[port]") . PHP_EOL;
                    return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
                }
            }
            $data['headers']['Authorization'][0] = $token;
            $connections = cache::getConnections();
            $parseContact = sip::extractURI($data['headers']['Contact'][0]);
            if (!array_key_exists('User-Agent', $headers)) $headers['User-Agent'][0] = 'null';
            if (!array_key_exists('expires', $parseContact['additional'])) $parseContact['additional']['expires'] = 3600;
            $connections[$uriBackup['user']] = [
                'address' => $info['address'],
                'port' => $info['port'],
                'userAgent' => $headers['User-Agent'][0],
                'timestamp' => time(),
                'packet' => $data,
                'expires' => $parseContact['additional']['expires'],
            ];
            if ($parseContact['additional']['expires'] < 2) $parseContact['additional']['expires'] = 2;
            Timer::after($parseContact['additional']['expires'] * 1000, function () use ($uriBackup) {
                $connections = cache::getConnections();
                unset($connections[$uriBackup['user']]);
                cache::updateConnections($connections);
            });
            cache::updateConnections($connections);
        }


        $data['headers']['To'][0] = $uriToRender;
        $data['headers']['From'][0] = $uriFromRender;
        $data['headers']['Via'][] = sip::teachVia(sip::extractURI($headers['From'][0])['user'], $info);


        $data['headers']['User-Agent'][0] = 'null';
        $uriContact = sip::extractURI($data['headers']['Contact'][0]);
        $uriContact['user'] = 's';
        $uriContact['peer']['host'] = network::getLocalIp();
        $uriContact['peer']['port'] = '5060';
        $uriContact['peer']['extra'] = false;
        $data['headers']['Contact'][0] = sip::renderURI($uriContact);
        $render = sip::renderSolution($data);
        if ($trunkData['register']) return $socket->sendto($trunkData['address'], 5060, $render, $info['server_socket']);


        if (!array_key_exists('Authorization', $headers)) {
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
                    "WWW-Authenticate" => ['Digest realm="' . cache::global()['interface']['server']['serverName'] . '", nonce="' . md5(time()) . '", algorithm=MD5'],
                    'CSeq' => $headers['CSeq'],
                ],
            ];
            $render = sip::renderSolution($authHeaders);
        } else {
            if (empty($parseContact)) {
                $parseContact = sip::extractURI($headers['Contact'][0]);
                $parseContact['additional']['expires'] = !empty($headers['Expires']) ? $headers['Expires'][0] : 120;
            }
            $uriFrom = sip::extractURI($headers['From'][0]);
            $uriFrom['user'] = $uri['user'];
            $headers['From'][0] = sip::renderURI($uriFrom);
            $modelOK = [
                "method" => "200",
                "methodForParser" => "SIP/2.0 200 OK",
                "headers" => [
                    "Via" => $headers['Via'],
                    "Max-Forwards" => !empty($headers['Max-Forwards']) ?? ['70'],
                    "From" => [
                        sip::renderURI([
                            'user' => $uriFromBackup['user'],
                            'peer' => [
                                'host' => network::getLocalIp(),
                                'port' => '5060',
                                'extra' => false
                            ],
                            'additional' => $uriFrom['additional']
                        ])
                    ],
                    "To" => $headers['To'],
                    "Call-ID" => $headers['Call-ID'],
                    "Content-Length" => [0],
                    "CSeq" => $headers['CSeq'],
                    "Server" => [cache::global()['interface']['server']['serverName']],
                    "Allow" => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE, INFO'],
                    'Supported' => ['replaces, 100rel, timer'],
                    'Expires' => [$parseContact['additional']['expires']],
                    'Contact' => [$data['headers']['Contact'][0]],
                    'Date' => [date('D, d M Y H:i:s T')],
                ],
            ];
            $render = sip::renderSolution($modelOK);
        }
        return $socket->sendto($info['address'], $info['port'], $render, $info['server_socket']);
    }
}

