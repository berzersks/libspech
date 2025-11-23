<?php

namespace handlers;

use Plugin\Utils\cli;
use plugins\Utils\cache;
use sip;

class sip401
{
    public static function resolve(\Swoole\Server $socket, $data, $info)
    {
        $headers = $data['headers'];
        $uri = sip::extractURI($headers['To'][0]);
        $via = sip::getVia($headers);
        $dataUser = sip::getTrunkByUserFromDatabase($via['user'])['account'];
        $uriFrom = sip::extractURI($headers['From'][0]);
        $uriFrom['user'] = $dataUser['u'];
        $uri['user'] = $dataUser['u'];
        $uriFromRender = sip::renderURI($uriFrom);
        $uriToRender = sip::renderURI($uri);
        $data['headers']['To'][0] = $uriToRender;
        $data['headers']['From'][0] = $uriFromRender;
        $data['headers']['Server'][0] = cache::global()['interface']['server']['serverName'];
        unset($data['headers']['Via'][1]);
        $render = sip::renderSolution($data);
        return $socket->sendto($via['host'], $via['port'], $render, $info['server_socket']);
    }
}