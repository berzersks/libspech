<?php

namespace handlers;

use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class options
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        return $socket->sendto(
            $info['address'],
            $info['port'],
            renderMessages::respondOptions($data['headers']),
            $info['server_socket']
        );
    }
}
