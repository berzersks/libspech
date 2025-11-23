<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class sip400
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $uri = sip::extractURI($headers['To'][0]);
        $secondVia = $headers['Via'][1];
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);


        $s = explode('SIP/2.0/UDP ', $secondVia)[1];
        $s1 = explode(';', $s)[0];
        list($host, $port) = explode(':', $s1);
        unset($data['headers']['Via'][1]);
        $findUserByAddress = sip::findUserByAddress([$host, $port]);
        if ($findUserByAddress) {
            $dataUser = sip::getTrunkByUserFromDatabase($findUserByAddress)['account'];
            $uriFrom = sip::extractURI($headers['From'][0]);
            $uriFrom['user'] = $dataUser['u'];
            $uri['user'] = $dataUser['u'];
            $uriFromRender = sip::renderURI($uriFrom);
            $uriToRender = sip::renderURI($uri);
            $data['headers']['To'][0] = $uriToRender;
            $data['headers']['From'][0] = $uriFromRender;
        }
        if (!empty($headers['Contact'])) {
            $uriContact = sip::extractURI($headers['Contact'][0]);
            $uriContact['peer']['host'] = network::getLocalIp();
            $newContact = sip::renderURI($uriContact);
            $data['headers']['Contact'][0] = $newContact;
        }


        $data['headers']['Server'][0] = cache::global()['interface']['server']['serverName'];
        $render = sip::renderSolution($data);
        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}