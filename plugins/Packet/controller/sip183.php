<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class sip183
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $uriFrom = sip::extractURI($headers['From'][0]);


        $callId = $headers['Call-ID'][0];
        $rules = cache::global()['rules'];
        $traces = cache::get("traces");
        if (!array_key_exists($callId, $traces)) {
            cache::subDefine("traces", $callId, []);
        }
        $uriFrom = sip::extractUri($headers['From'][0]);
        cache::subJoin('traces', $callId, [
            "direction" => "received",
            "receiveFrom" => trunkController::renderURI(["user" => $uriFrom["user"], "peer" => ["host" => $info["address"], "port" => $info["port"]]]),
            "data" => $data,
        ]);
        return true;
        $headers = $data['headers'];
        $uriFrom = sip::extractURI($headers['From'][0]);


        $callId = $headers['Call-ID'][0];
        $rules = cache::global()['rules'];

        print cli::color('bold_green', 'SIP 183 -> ' . sip::extractURI($headers['To'][0])['user'] . ' ' . date('H:i:s')) . PHP_EOL;

        if (array_key_exists($callId, $rules)) {
            if ($rules[$callId] == $uriFrom['user']) {

                return false;
            }
        }
        if ($headers['CSeq'][0] == '1 INVITE') {
            print cli::color('red', sip::renderSolution($data)) . PHP_EOL;
            return false;
        }
        $uri = sip::extractURI($headers['To'][0]);
        $secondVia = $headers['Via'][1];
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
        if (array_key_exists('Contact', $headers)) {
            $uriContact = sip::extractURI($headers['Contact'][0]);
            $uriContact['peer']['host'] = network::getLocalIp();
            $newContact = sip::renderURI($uriContact);
            $data['headers']['Contact'][0] = $newContact;
        }
        $data['headers']['Server'][0] = cache::global()['interface']['server']['serverName'];
        if (array_key_exists('sdp', $data)) unset($data['sdp']);
        if (array_key_exists('Content-Type', $data['headers'])) unset($data['headers']['Content-Type']);
        $data['methodForParser'] = 'SIP/2.0 180 Ringing';


        $render = sip::renderSolution($data);
        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}