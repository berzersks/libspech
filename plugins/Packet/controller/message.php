<?php

namespace handlers;

use DialogManager;
use Plugin\Utils\cli;
use plugin\Utils\network;
use ObjectProxy;
use plugins\Utils\cache;
use sip;

class message
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $uriTo = sip::extractURI($data['headers']['To'][0]);
        $uriFrom = sip::extractURI($data['headers']['From'][0]);
        $dialogId = $data['headers']['Call-ID'][0];
        $callId = $dialogId;

        $extension = $uriFrom['user'];
        $callId = $headers['Call-ID'][0];


        if (\trunk::userIsTrunked($extension)) {
            if (method_exists('\trunk\message', 'resolve')) {
                return call_user_func('\trunk\message::resolve', $socket, $data, $info);
            } else
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        }


        $connection = cache::findConnection($uriTo['user']);
        if ($connection === null) $connection = cache::findConnection($uriFrom['user']);

        $rendered = sip::renderSolution($data);
        if ($connection !== null) {
            $socket->sendto($connection['address'], $connection['port'], $rendered);
            // responder de volta com um 200 OK
            $socket->sendto($info['address'], $info['port'], renderMessages::respond200OK($data['headers']));
        }

        return true;

    }
}