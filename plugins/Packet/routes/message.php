<?php

namespace trunk;

use co;
use handlers\renderMessages;
use Plugin\Utils\cli;
use plugin\Utils\network;
use ObjectProxy;
use plugins\Utils\cache;
use Random\RandomException;
use sip;
use Swoole\Coroutine;
use trunkController;

class message
{


    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $callId = $data['headers']['Call-ID'][0];
        $uriTo = sip::extractURI($data['headers']['To'][0]);
        $uriFrom = sip::extractURI($data['headers']['From'][0]);

        $connections = cache::getConnections();
        if (!array_key_exists($uriTo['user'], $connections)) return $socket->sendto(
            $info['address'],
            $info['port'],
            renderMessages::baseResponse($headers, "480", "Usuário desconectado.")
        );
        $connectionReferTo = $connections[$uriTo['user']];
        $render = sip::renderSolution($data);
        $socket->sendto($connectionReferTo['address'], $connectionReferTo['port'], $render);
        $socket->sendto($info['address'], $info['port'], renderMessages::respond200OK($data['headers']));


        return true;
    }
}