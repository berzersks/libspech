<?php

namespace handlers;

use ObjectProxy;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;
use trunkController;

class sip180
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $callId = $headers['Call-ID'][0];

        $uriFrom = sip::extractUri($headers['From'][0]);
        cache::subJoin('traces', $callId, [
            "direction" => "received",
            "receiveFrom" => trunkController::renderURI(["user" => $uriFrom["user"], "peer" => ["host" => $info["address"], "port" => $info["port"]]]),
            "data" => $data,
        ]);
        return false;
        $headers = $data['headers'];
        cli::pcl('Received SIP 180 Ringing' . PHP_EOL . sip::renderSolution($data), 'bold_blue');

        $uriFrom = sip::extractURI($headers['From'][0]);

        if (!isset($headers['Call-ID'])) return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
        $callId = $headers['Call-ID'][0];

        /** @var ObjectProxy $sop */
        $sop = cache::get('swooleObjectProxy');

        if ($sop->isset($callId)) {
            if (method_exists('\trunk\sip180', 'resolve')) {
                return call_user_func('\trunk\sip180::resolve', $socket, $data, $info);
            } else
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));

        }


        $rules = cache::global()['rules'];

        if (array_key_exists($callId, $rules)) {
            if ($rules[$callId] == $uriFrom['user']) {
                print cli::color('red', sip::renderSolution($data)) . PHP_EOL;
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
        $uriContact = sip::extractURI($headers['Contact'][0]);
        $uriContact['peer']['host'] = network::getLocalIp();
        $newContact = sip::renderURI($uriContact);
        $data['headers']['Contact'][0] = $newContact;
        $data['headers']['Server'][0] = cache::global()['interface']['server']['serverName'];
        $render = sip::renderSolution($data);
        return $socket->sendto($host, $port, $render, $info['server_socket']);
    }
}