<?php

namespace trunk;

use co;
use handlers\renderMessages;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\ObjectProxy;
use plugins\Utils\cache;
use Random\RandomException;
use sip;
use Swoole\Coroutine;
use trunkController;

class sip180
{


    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        return false;
        $headers = $data['headers'];
        if (!isset($headers['Call-ID'])) return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        $callId = $headers['Call-ID'][0];
          $traces = cache::get("traces");
        if (!array_key_exists($callId, $traces)) {
            cache::subDefine("traces", $callId, []);
        }
        $uriFrom = sip::extractUri($headers['From'][0]);
        cache::subJoin('traces', $callId, [
            "direction" => "received",
            "receiveFrom" => trunkController::renderURI(["user" => $uriFrom["user"], "peer" => ["host" => $info["address"], "port" => $info["port"]]]),
            "data" => $data,
        ]);


        /** @var ObjectProxy $sop */
        $sop = cache::global()['swooleObjectProxy'];
        if (!$sop->isset($callId)) return $socket->sendto($info['address'], $info['port'], renderMessages::respondUserNotFound($headers));

        /** @var trunkController $trunkController */
        $trunkController = $sop->get($callId);

        $ringModel = [
            'method' => '180',
            'headers' => [
                'Call-ID' => $headers['Call-ID'],
                'CSeq' => $headers['CSeq'],
                'From' => $headers['From'],
                'To' => $headers['To'],
                'Via' => $headers['Via'],
                'Contact' => $headers['Contact'],
                'Content-Length' => '0',
            ],
        ];
        $solved = sip::renderSolution($ringModel);


        return true;
    }
}