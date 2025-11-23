<?php

namespace handlers;

use plugins\Utils\cache;
use sip;

class sip503
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);


        // checar quem disse que a chamada não existe e se for de um ip de alguém que está conectado ao servidor persistir a chamada
        $address = $info['address'];
        $port = $info['port'];
        $connections = cache::getConnections();
        $found = false;
        foreach ($connections as $connection) {
            if (($connection['address'] == $address) and ($connection['port'] == $port)) {
                $found = true;
                break;
            }
        }
        if ($found) return false;
        // responde com 200 OK
        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));

        cache::persistExpungeCall($callId);
        return true;
    }
}