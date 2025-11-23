<?php

namespace trunk;

use handlers\renderMessages;
use Plugin\Utils\cli;
use sip;
use Swoole\Coroutine;
use Swoole\Table;

class sip200
{


    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {


        $headers = $data['headers'];
        $cseq = sip::csq($headers['CSeq'][0]);
        if ($cseq === 'BYE') {
            $callId = $headers['Call-ID'][0];
            if ($socket->tpc->exist($callId)) {


                // $socket->tpc->del($callId);
                // cli::pcl("Deletando $callId novamente!!!!!!!!", 'bold_red');
                return true;


            }

        }


        if (!isset($headers['Call-ID'])) {
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }
        $callId = $headers['Call-ID'][0];

        /** @var Table $tpc */
        $tpc = $socket->tpc;
        $uriFrom = sip::extractURI($headers['From'][0]);
        $uriTo = sip::extractURI($headers['To'][0]);

        $tpcData = json_decode($tpc->get($callId, 'data'), true);
        if (!$tpc->exist($callId)) return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        if (!array_key_exists('sdp', $data)) return false;


        $callIdOld = $tpcData['deleteCall'];

        if (!$tpc->exist($callIdOld)) {
            $ack = renderMessages::respondAckModel($data['headers']);
            $socket->sendto($info['address'], $info['port'], $ack, $info['server_socket']);

            $newHeaders = $data['headers'];
            $newHeaders['CSeq'][0] = intval($newHeaders['CSeq'][0]) + 1 . " BYE";

            $modelBye = renderMessages::generateBye($newHeaders);
            $socket->sendto($info['address'], $info['port'], sip::renderSolution($modelBye));
            $tpc->del($callId);
            return true;
        }
        if (array_key_exists('deleteCall', $tpcData)) {
            if ($tpc->exist($callIdOld)) $tpc->del($callIdOld);
        }
        $dialogProxy = $tpcData['dialogProxy'] ?? [];

        foreach ($dialogProxy as $user => $value) {
            if ($value['startBy'] == true) {
                $cloneValue = $value;
                $cloneValue['username'] = $uriFrom['user'];
                unset($dialogProxy [$user]);
                $dialogProxy [$uriTo['user']] = $cloneValue;
            }
        }
        $tpcData['dialogProxy'] = $dialogProxy;


        $tpcData['refer'] = 'yes';
        $socket->tpc->set($callId, ['data' => json_encode($tpcData, JSON_PRETTY_PRINT)]);
        $tpcData = json_decode($tpc->get($callId, 'data'), true);
        $dialogProxy = $tpcData['dialogProxy'] ?? [];
        foreach ($dialogProxy as $usn => $duc) {
            $dialogProxy[$usn]['refer'] = true;
        }
        $tpcData['dialogProxy'] = $dialogProxy;


        $ack = renderMessages::respondAckModel($data['headers']);
        $socket->sendto($info['address'], $info['port'], $ack, $info['server_socket']);
        if (array_key_exists('friendCall', $tpcData)) {
            $socket->sendto($tpcData['friendCall']['address'], $tpcData['friendCall']['port'], sip::renderSolution($tpcData['data']));
        }


        $byeSocketListener = new Coroutine\Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $byeSocketListener->bind('0.0.0.0', $tpcData['trunkSocketBind']);
        $socket->tpc->set($callId, ['data' => json_encode($tpcData, JSON_PRETTY_PRINT)]);
        while ($tpc->exist($callId)) {
            Coroutine::sleep(1);
            $dataListen = $byeSocketListener->recvfrom($peerBye, 1);
            if (!$dataListen) continue;
            $parse = sip::parse($dataListen);
            cli::pcl($parse['methodForParser'], 'bold_red');
            $newHeaders = $data['headers'];
            $newHeaders['CSeq'][0] = intval($newHeaders['CSeq'][0]) + 1 . " BYE";
            $modelBye = renderMessages::generateBye($newHeaders);
            $socket->sendto($info['address'], $info['port'], sip::renderSolution($modelBye));
            break;
        }
        $tpc->del($callId);
        $tpc->del($callIdOld);
        $byeSocketListener->close();

        cli::pcl("Deletando $callId", 'bold_red');
        return true;
    }
}