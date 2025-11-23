<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class sip500
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);

        return true;
        $headers = $data['headers'];
        $uri = sip::extractURI($headers['To'][0]);
        $secondVia = $headers['Via'][1];
        \callHandler::resolveCloseCall($data['headers']['Call-ID'][0]);


        print cli::color('bold_green', "[$data[method] $info[address]:$info[port] -> $info[server_socket]") . PHP_EOL;
        $via = sip::getVia($headers);

        $findUserByAddress = sip::findUserByAddress([$via['host'], $via['port']]);
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
        $cseq = sip::csq($headers['CSeq']);


        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}