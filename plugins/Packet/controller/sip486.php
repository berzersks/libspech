<?php

namespace handlers;

use plugins\Utils\cache;
use sip;

class sip486
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $modelAck = renderMessages::respondAckModel($data['headers']);
        $socket->sendto($info['address'], $info['port'], $modelAck, $info['server_socket']);
        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($data['headers']), $info['server_socket']);
        return false;
        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];
        $secondVia = $headers['Via'][0];
        $s = explode('SIP/2.0/UDP ', $secondVia)[1];
        $s1 = explode(';', $s)[0];
        list($host, $port) = explode(':', $s1);
        unset($data['headers']['Via'][1]);
        $render = sip::renderSolution($data);
        $dialogProxy = cache::global()['dialogProxy'];
        /** @var ObjectProxy $sopj */
        $sobj = cache::global()['swooleObjectProxy'];
        if ($sobj->isset($callId)) {
            /** @var \trunkController $trunkController */
            $trunkController = $sobj->get($callId);
            // ignorar com mensagem de erro

            print "[486] Usuário {$data['headers']['From'][0]} participa da chamada $callId." . PHP_EOL;
            $message = renderMessages::respond486Busy($headers);
            $getAddress = cache::findConnection(sip::extractUri($trunkController->headers200['headers']['To'][0])['user']);
            if (!$getAddress) return false;
            return $trunkController->socket->sendto($getAddress['address'], $getAddress['port'], $message);
        }
        // 5

        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);
        if (array_key_exists($callId, $dialogProxy)) {
            cache::persistExpungeCall($callId);
        }


        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}