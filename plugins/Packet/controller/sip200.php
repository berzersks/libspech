<?php /** @noinspection ALL */

namespace handlers;

use Cassandra\Time;
use DialogManager;
use Plugin\Utils\cli;
use plugin\Utils\network;
use ObjectProxy;
use plugins\Utils\cache;
use sip;
use Swoole\Timer;

class sip200
{
    private const UDP_SPLIT_STRING = 'SIP/2.0/UDP ';
    private const CONTACT_DEFAULT_PORT = 5060;

    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        if (!isset($headers['Call-ID'])) return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        $callId = $headers['Call-ID'][0];

        /** @var ObjectProxy $sop */
        $sop = cache::get('swooleObjectProxy');
        if (method_exists('\trunk\sip200', 'resolve')) {
            return call_user_func('\trunk\sip200::resolve', $socket, $data, $info);
        }


        if ($sop->isset($callId)) {
            if (method_exists('\trunk\sip200', 'resolve')) {
                return call_user_func('\trunk\sip200::resolve', $socket, $data, $info);
            } else
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }

        $uriFromExtract = sip::extractURI($data['headers']['From'][0]);


        if (!empty($headers['Via']) and !empty($headers['Via'][1])) {
            $viaHeader = $headers['Via'][1];
        }
        $toUri = sip::extractURI($headers['To'][0]);
        $fromUri = sip::extractURI($headers['From'][0]);
        $csq = sip::csq($headers['CSeq'][0]);
        if ($csq == 'OPTIONS') {
            return true;
        }


        $toUri = sip::extractURI($headers['To'][0]);
        if (!array_key_exists('Max-Forwards', $headers)) $headers['Max-Forwards'] = ['70'];
        if (!empty($headers['Via'][1])) {
            [$host, $port] = self::parseViaHeader($headers['Via'][1]);
        } else {
            $localIp = network::getLocalIp();
            $referTo = sip::extractURI($headers['To'][0])['user'];


            $ackHeaders = [
                "method" => "ACK",
                "methodForParser" => "ACK sip:{$referTo}@{$localIp} SIP/2.0",
                "headers" => [
                    "Via" => $headers['Via'], // Reutilizar a mesma Via do INVITE
                    "Max-Forwards" => !$headers['Max-Forwards'] ? ['70'] : $headers['Max-Forwards'],
                    "From" => $headers['From'], // O mesmo cabeçalho From do INVITE
                    "To" => $headers['To'], // O mesmo cabeçalho To do INVITE (com o mesmo tag)
                    "Call-ID" => $headers['Call-ID'], // Deve ser idêntico ao do INVITE
                    "CSeq" => ["1 ACK"], // Alterar apenas o método para ACK
                    "Content-Length" => [0], // ACK normalmente não contém corpo
                ],
            ];

            return $socket->sendto($info['address'], $info['port'], renderMessages::respondAckModel($headers), $info['server_socket']);
        }
        if (!empty($data['headers']['Via'][1])) {
            $v1 = $data['headers']['Via'][1];
            if (str_contains($v1, ';refer')) {
                $localIp = network::getLocalIp();
                $referTo = sip::extractURI($headers['To'][0])['user'];
                $ackHeaders = [
                    "method" => "ACK",
                    "methodForParser" => "ACK sip:{$referTo}@{$localIp} SIP/2.0",
                    "headers" => [
                        "Via" => $headers['Via'], // Reutilizar a mesma Via do INVITE
                        "Max-Forwards" => !empty($headers['Max-Forwards']) ?? ['70'],
                        "From" => $headers['From'], // O mesmo cabeçalho From do INVITE
                        "To" => $headers['To'], // O mesmo cabeçalho To do INVITE (com o mesmo tag)
                        "Call-ID" => $headers['Call-ID'], // Deve ser idêntico ao do INVITE
                        "CSeq" => ["1 ACK"], // Alterar apenas o método para ACK
                        "Content-Length" => [0], // ACK normalmente não contém corpo 4000 esse usuario precisar liberar essa porta e misturar 5000 para 3000

                    ],
                ];
                $socket->sendto($info['address'], $info['port'], sip::renderSolution($ackHeaders), $info['server_socket']);
                $tags = cache::global()['tags'];
                $tags[$headers['Call-ID'][0]] = [
                    'to' => $toUri,
                    'from' => sip::extractURI($headers['From'][0]),
                    'vias' => $headers['Via'],
                ];
                cache::define('tags', $tags);
            }
        }
        $user = sip::findUserByAddress([$host, $port]);

        if ($user) {
            $userData = sip::getTrunkByUserFromDatabase($user)['account'];
            $fromUri = sip::extractURI($headers['From'][0]);
        }

        $data['headers']['Server'][0] = cache::global()['interface']['server']['serverName'];

        if (isset($headers['Contact'])) {
            $contactUri = self::updateContactUri($headers['Contact'][0]);
            $data['headers']['Contact'][0] = sip::renderURI($contactUri);
            $extractContact = sip::extractURI($headers['Contact'][0]);
            $pu = explode('-', $extractContact['user'])[0];
            if ($pu == $toUri['user']) {
                $toUriBackup = $toUri;
                $fromUriBackup = $fromUri;
                $toUri['user'] = $fromUriBackup['user'];
                $fromUri['user'] = $toUriBackup['user'];
                $data['headers']['From'][0] = sip::renderURI($fromUri);
                $data['headers']['To'][0] = sip::renderURI($toUri);
            }


        }

        $callId = trim($headers['Call-ID'][0]);

        if (!empty($data['sdp'])) {
            // checar se o callId já possui um membro, se não é porque a chamada foi cancelada
            $dialogProxy = cache::global()['dialogProxy'];
            if (!array_key_exists($callId, $dialogProxy)) return false;
            if (array_key_exists($callId, $dialogProxy)) {
                $membersCount = count($dialogProxy[$callId]);

                if ($membersCount == 0) {
                    print cli::color('magenta', "Chamada cancelada detectada") . PHP_EOL;
                    return false;
                }
            }


            $fromUri = sip::extractURI($data['headers']['From'][0]);
            $toUri = sip::extractURI($data['headers']['To'][0]);
            $mediaIp = null;
            if (str_contains($data["sdp"]["c"][0], "IN IP4")) {
                $mediaIp = value($data["sdp"]["c"][0], "IN IP4 ", PHP_EOL);
            } elseif (str_contains($data["sdp"]["c"][0], "IN IP6")) {
                $mediaIp = value($data["sdp"]["c"][0], "IN IP6 ", PHP_EOL);
            }
            $mediaPort = (int)value($data["sdp"]["m"][0], "audio ", " ");


            $peerProxyPort = 0;
            if (str_contains($viaHeader, 'refer')) {
                $dialogProxy = cache::global()['dialogProxy'];
                $callId = $headers['Call-ID'][0];
                $dialog = $dialogProxy[$callId];
                foreach ($dialog as $user => $midia) {
                    if (trim($user) !== trim($toUri['user'])) {
                        if (str_contains($viaHeader, 'refer')) {
                            if ((trim($user) !== trim($toUri['user'])) && (trim($user) !== trim(sip::extractURI($data['headers']['From'][0])['user']))) {
                                $peerProxyPort = $midia['proxyPort'];
                                $dialogProxy[$callId][$user]['peerPort'] = $mediaPort;
                                $dialogProxy[$callId][$user]['peerIp'] = $mediaIp;
                                $dialogProxy[$callId][$user]['referredTo'] = $toUri['user'];
                                cache::define('dialogProxy', $dialogProxy);
                                print "$user agora envia na porta de $mediaIp:$mediaPort" . PHP_EOL;
                                break;
                            }
                        }
                    }
                }
            } else {
                $dialogProxy = cache::global()['dialogProxy'];
                if (array_key_exists($callId, $dialogProxy)) {
                    $dialog = $dialogProxy[$callId];
                    foreach ($dialog as $user => $midia) {
                        if (trim($user) == trim($fromUri['user'])) {
                            $peerProxyPort = $midia['proxyPort'];
                            break;
                        }
                    }
                }
                if ($peerProxyPort == 0) $peerProxyPort = network::getFreePort();
            }
            if ($peerProxyPort == 0) $peerProxyPort = network::getFreePort();


            $localIp = network::getLocalIp();


            $data['sdp']['c'][0] = "IN IP4 " . network::getLocalIp();
            $data['sdp']['o'][0] = str_replace($mediaIp, network::getLocalIp(), $data['sdp']['o'][0]);
            $data['sdp']['s'][0] = cache::global()['interface']['server']['serverName'];
            $data['sdp']['m'][0] = str_replace($mediaPort, $peerProxyPort, $data['sdp']['m'][0]);
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


            $data['sdp'] = [
                'v' => ['0'],
                'o' => ["root 1561393747 1561393747 IN IP4 $localIp"],
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
            ];


            $dialogProxy = cache::global()['dialogProxy'];
            $dialogProxy[$callId][$fromUri['user']] = [
                'proxyPort' => $peerProxyPort,
                'proxyIp' => network::getLocalIp(),
                'peerPort' => $mediaPort,
                'peerIp' => $info['address'],
                'received' => true,
                'startedAt' => time(),
                'headers' => $data['headers'],
                'username' => $fromUri['user'],
                'status' => '200',
            ];
            cache::define('dialogProxy', $dialogProxy);


            $dialogProxy = cache::global()['dialogProxy'];
            $callId = $headers['Call-ID'][0];
            $dialog = $dialogProxy[$callId];
            foreach ($dialog as $user => $midia) {
                if (str_contains($viaHeader, 'refer')) {
                    if ((trim($user) !== trim($toUri['user'])) && (trim($user) !== trim(sip::extractURI($data['headers']['From'][0])['user']))) {
                        print $user . " esse usuario precisar liberar essa porta e misturar " . trim($toUri['user']) . " para " . sip::extractURI($data['headers']['From'][0])['user'] . PHP_EOL;
                        $dialogProxy[$callId][$user]['peerPort'] = $mediaPort;
                        $dialogProxy[$callId][$user]['peerIp'] = $mediaIp;
                        $dialogProxy[$callId][$user]['referredTo'] = $toUri['user'];
                        break;
                    }
                }
            }
            cache::define('dialogProxy', $dialogProxy);

        } else if (!empty($userData)) {
            $fromUri['user'] = $userData['u'];
            $toUri['user'] = $userData['u'];
            $data['headers']['From'][0] = sip::renderURI($fromUri);
            $data['headers']['To'][0] = sip::renderURI($toUri);
        }

        unset($data['headers']['Via'][1]);
        $renderedSolution = sip::renderSolution($data);


        if (str_contains($viaHeader, 'refer')) {
            $findConnection = value($viaHeader, 'extension=', ';');
            $findConnection = cache::findConnection($findConnection);
            if ($findConnection) {
                $host = $findConnection['address'];
                $port = $findConnection['port'];
            }
            return false;
        } else {
            return $socket->sendto($host, $port, $renderedSolution, $info['server_socket']);

        }
    }

    private static function parseViaHeader(string $viaHeader): array
    {
        $s = explode(self::UDP_SPLIT_STRING, $viaHeader)[1];
        $s1 = explode(';', $s)[0];
        return explode(':', $s1);
    }

    private static function updateContactUri(string $contactHeader): array
    {
        $contactUri = sip::extractURI($contactHeader);
        $contactUri['peer']['port'] = self::CONTACT_DEFAULT_PORT;
        $contactUri['peer']['host'] = network::getLocalIp();
        return $contactUri;
    }


}