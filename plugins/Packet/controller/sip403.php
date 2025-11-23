<?php

namespace handlers;

use plugins\Utils\cache;
use sip;

class sip403
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        print "SIP 403\n";
        print sip::renderSolution($data) . "\n";

        $database = cache::global()['database'];
        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];
        $uri = sip::extractURI($headers['To'][0]);
        $secondVia = $headers['Via'][1];
        $s = explode('SIP/2.0/UDP ', $secondVia)[1];
        $s1 = explode(';', $s)[0];
        list($host, $port) = explode(':', $s1);
        unset($data['headers']['Via'][1]);
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);

        $dialogs = cache::global()['dialogProxy'];
        if (array_key_exists($callId, $dialogs)) {
            cache::persistExpungeCall($callId);
        }


        $render = sip::renderSolution($data);
        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}