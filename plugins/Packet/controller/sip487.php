<?php

namespace handlers;

use plugins\Utils\cache;
use sip;

class sip487
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {

        return $socket->sendto($info['address'], $info['port'], renderMessages::respondAckModel($data['headers']));
        return true;

        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];
        $secondVia = $headers['Via'][1];
        $s = explode('SIP/2.0/UDP ', $secondVia)[1];
        $s1 = explode(';', $s)[0];
        list($host, $port) = explode(':', $s1);
        unset($data['headers']['Via'][1]);
        $render = sip::renderSolution($data);
        $dialogProxy = cache::global()['dialogProxy'];
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);
        if (array_key_exists($callId, $dialogProxy)) {
            cache::persistExpungeCall($callId);
        }
        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}