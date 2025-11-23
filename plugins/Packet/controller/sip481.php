<?php

namespace handlers;

use plugins\Utils\cache;
use sip;

class sip481
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];
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
        cache::persistExpungeCall($callId);
        return true;
    }
}