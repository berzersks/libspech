<?php

namespace handlers;

use DialogManager;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;
use function Swoole\Coroutine\Http\post;

class subscribe
{

    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {
        $modelNoPermitted = renderMessages::respond487RequestTerminated($data['headers']);
        return $socket->sendto($info['address'], $info['port'], $modelNoPermitted);
    }


}
