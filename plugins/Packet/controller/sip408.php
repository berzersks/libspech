<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class sip408
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $uri = sip::extractURI($headers['To'][0]);
        $secondVia = $headers['Via'][1];
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);


        return true;
    }
}