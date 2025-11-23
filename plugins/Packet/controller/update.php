<?php

namespace handlers;

use DialogManager;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;
use function Swoole\Coroutine\Http\post;

class update
{

    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];


        // Atualizar dados no cache
        $toUri = sip::extractURI($headers['To'][0]);
        $mediaIp = value($data["sdp"]["c"][0], "IN IP4 ", PHP_EOL);
        if (!$mediaIp) $mediaIp = value($data["sdp"]["c"][0], "IN IP6 ", PHP_EOL);


        $mediaPort = (int)value($data["sdp"]["m"][0], "audio ", " ");
        $localIp = network::getLocalIp();
        $peerProxyPort = network::getFreePort();

        if (network::isPrivateIp($mediaIp)) {
            $mediaIp = $info['address'];
        }

        print cli::color('bold_blue', "[update] Mídia original > $mediaIp:$mediaPort") . PHP_EOL;

        // Atualizar o SDP para redirecionar ao proxy RTP
        $data['sdp']['c'][0] = "IN IP4 " . $localIp;
        $data['sdp']['m'][0] = str_replace($mediaPort, $peerProxyPort, $data['sdp']['m'][0]);

        foreach ($data["sdp"] as $key => $values) {
            foreach ($values as $k => $v) {
                $getIpx = value($v, "IN IP4 ", PHP_EOL);
                if (!str_contains($v, $localIp)) {
                    $data["sdp"][$key][$k] = str_replace($getIpx, $localIp, $v);
                }
            }
        }


        $dialogProxy = cache::global()['dialogProxy'];
        $callId = $headers['Call-ID'][0];
        $dialog = $dialogProxy[$callId];
        foreach ($dialog as $user => $midia) {
            if ((trim($user) !== trim($toUri['user'])) && (trim($user) !== trim(sip::extractURI($data['headers']['From'][0])['user']))) {
                print $user . " esse usuario precisar liberar essa porta e misturar " . trim($toUri['user']) . " para " . sip::extractURI($data['headers']['From'][0])['user'] . PHP_EOL;
                $dialogProxy[$callId][$user]['peerPort'] = $mediaPort;
                $dialogProxy[$callId][$user]['peerIp'] = $mediaIp;
                break;

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
        cache::define('dialogProxy', $dialogProxy);


        go(function () use ($peerProxyPort, $mediaIp, $mediaPort, $localIp, $callId, $headers) {
            createProxyRTP([
                'proxyPort' => $peerProxyPort,
                'proxyIp' => network::getLocalIp(),
                'peerPort' => $mediaPort,
                'peerIp' => $mediaIp,
                'callId' => $callId,
                'username' => sip::extractURI($headers['From'][0])['user'],
            ]);
        });


        print cli::color('green', "[update] Sessão RTP atualizada para Call-ID: $callId") . PHP_EOL;


        // Enviar resposta 200 OK ao cliente
        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));

        // Reencaminhar a mensagem UPDATE para o próximo destino
        $find = value($headers['To'][0], 'sip:', '@');
        $findConnection = cache::findConnection($find);
        if ($findConnection) {
            $findFrom = value($headers['From'][0], 'sip:', '@');
            $data['headers']['Via'][] = "SIP/2.0/UDP {$info['address']}:{$info['port']};branch=z9hG4bK" . uniqid() . ';extension=' . $findFrom;

            $contactURI = sip::extractURI($headers['Contact'][0]);
            $contactURI['peer']['port'] = 5060;
            $contactURI['peer']['host'] = $localIp;
            $data['headers']['Contact'][0] = sip::renderURI($contactURI);

            $render = sip::renderSolution($data);
            print cli::color('yellow', $render) . PHP_EOL;

            return $socket->sendto($findConnection['address'], $findConnection['port'], $render, $info['server_socket']);
        }

        print cli::color('red', "[update] Destino para reencaminhar não encontrado!") . PHP_EOL;
        return false;
    }


}
