<?php

namespace handlers;

use Plugin\Utils\cli;
use plugins\Utils\cache;
use sip;

class ack
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {

        $headers = $data['headers'];
        $ack = $data['methodForParser'];


        $getUser = value($ack, 'sip:', '@');
        $uriFrom = sip::extractURI($headers['From'][0]);
        $uriTo = sip::extractURI($headers['To'][0]);
        print cli::color('red', "ack persistente $info[address] $info[port] -> $getUser") . PHP_EOL;
        cli::pcl(sip::renderSolution($data), 'bold_blue');


        $details = sip::getTrunkByUserFromDatabase($uriFrom['user']);
        // if (!$details) return $socket->sendto($info['address'], $info['port'], renderMessages::respondUserNotFound($data['headers']));
        if (!$details) return false;


        print cli::color('red', "ack persistente $info[address] $info[port] -> $getUser") . PHP_EOL;

        $trunk = $details['trunk'];


        $callId = $headers['Call-ID'][0];
        if (!empty($headers['Authorization'][0])) {
            $auths = cache::global()['auth'];
            $auths[$callId] = $headers['Authorization'][0];
            cache::define('auth', $auths);
        }


        if (!$trunk['r']) return false;

        print cli::color('red', "ack persistente $info[address] $info[port] -> $getUser") . PHP_EOL;

        $dialogProxy = cache::global()['dialogProxy'];
        if (array_key_exists($callId, $dialogProxy)) {
            $friends = $dialogProxy[$callId];
            $friends = array_reverse($friends, true);
            foreach ($friends as $username => $friend) {
                go(function () use ($callId, $username, $friend) {
                    print cli::color('yellow', "proxy $friend[proxyIp] $friend[proxyPort] -> $friend[peerIp] $friend[peerPort]") . PHP_EOL;
                    if (array_key_exists('proxyPort', $friend))
                        createProxyRTP([
                            'proxyPort' => $friend['proxyPort'],
                            'proxyIp' => $friend['proxyIp'],
                            'peerPort' => $friend['peerPort'],
                            'peerIp' => $friend['peerIp'],
                            'callId' => $callId,
                            'username' => $username,
                        ]);
                });
            }
        }


        if (str_contains($getUser, '-')) $getUser = explode('-', $getUser)[0];
        $checkLocal = sip::getTrunkByUserFromDatabase($getUser);
        if ($checkLocal) $findConnection = cache::findConnection($getUser);
        else $findConnection = false;
        if (!$findConnection) {
            $getUser = $uriFrom['user'];
            $findUser = sip::getTrunkByUserFromDatabase($getUser);
            if ($findUser) {
                $findConnection = [
                    'address' => $findUser['trunk']['h'],
                    'port' => 5060
                ];
            }
        }
        if (!empty($headers['Authorization'])) {
            $token = sip::security($data, $info);
            if (!$token) {
                print cli::color('red', "ack não enviado para $getUser") . PHP_EOL;
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
            }
            $data['headers']['Authorization'][0] = $token;
        }
        if ($findConnection) {
            $render = sip::renderSolution($data);
            print cli::color('red', "ack $findConnection[address] $findConnection[port] -> $getUser") . PHP_EOL;
            return $socket->sendto($findConnection['address'], $findConnection['port'], $render);
        }
        print cli::color('red', "ack não enviado para $getUser") . PHP_EOL;
        return true;
    }
}
