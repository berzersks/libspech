<?php

namespace handlers;

use Plugin\Utils\cli;
use plugins\Utils\cache;
use sip;
use Swoole\Coroutine;
use Swoole\Timer;
use trunkController;

class bye
{
    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {
        cli::pcl(sip::renderSolution($data), 'bold_red');
        $headers = $data['headers'] ?? [];
        $bye = $data['methodForParser'];
        $byeNumber = explode('@', str_replace('sip:', '', $bye))[0];
        $from = $headers['From'][0] ?? null;
        $to = $headers['To'][0] ?? null;
        $callId = $headers['Call-ID'][0];
        $uriFrom = sip::extractURI($from);
        $uriTo = sip::extractURI($to);
        $rules = cache::global()['rules'];

        $purge = false;
        if (array_key_exists('Reason', $headers)) {
            $purge = true;
        }


        $callRow = json_decode($socket->tpc->get($callId, 'data'), true);
        foreach ($callRow['hangups'] as $uriHangUp => $hangup) {
            $hangup['info']['port'] = intval($hangup['info']['port']);
            $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($hangup['model']));
        }




        if ($byeNumber == $uriFrom['user']) {
            $byeNumber = $uriTo['user'];
            $tpcData = $socket->tpc->get($callId, 'data');
            if ($tpcData) {
                $tpcDataDecoded = json_decode($tpcData, true);
                if (array_key_exists('friendCall', $tpcDataDecoded)) {
                    if ($tpcDataDecoded['friendCall']) {
                        $tpcDataDecoded['hangups'][$byeNumber]['model'] = $data;
                        $socket->tpc->set($callId, ['data' => json_encode($tpcDataDecoded, JSON_PRETTY_PRINT)]);
                    }
                }
            }
        }
        foreach ($socket->tpc as $cid => $cd) {
            $tryTpc = $socket->tpc->get($cid, 'data');
            if ($tryTpc) {
                $tryTpcDecoded = json_decode($tryTpc, true);
                if (array_key_exists('originalCallId', $tryTpcDecoded))
                    if ($tryTpcDecoded['originalCallId'] == $callId) {
                        $callId = $cid;
                    }
            }
        }


        if ($socket->tpc->exist($callId)) {
            $tpcData = $socket->tpc->get($callId, 'data');


            if ($tpcData) {
                $tpcDataDecoded = json_decode($tpcData, true);
                $hangups = $tpcDataDecoded['hangups'] ?? [];
                if (array_key_exists($byeNumber, $hangups)) {
                    $hangup = $hangups[$byeNumber];


                    $model = $hangup['model'] ?? [];
                    if (!$tpcDataDecoded['refer']) {


                        $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($model));
                        unset($hangups[$byeNumber]);
                        unset($hangups[$uriFrom['user']]);


                    } else {
                        if (array_key_exists($uriFrom['user'], $hangups)) {
                            $socket->sendto($hangups[$uriFrom['user']]['info']['host'], $hangups[$uriFrom['user']]['info']['port'], sip::renderSolution($hangups[$uriFrom['user']]['model']));
                            unset($hangups[$uriFrom['user']]);
                        }
                    }
                    $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
                    // nova estrategia de desligamento, ENVIA TODOS
                    foreach ($hangups as $hangup) {
                        $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($hangup['model']));
                        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
                    }


                    $tpcDataDecoded['hangups'] = $hangups;
                    cli::pcl("Enviando desligamento para $byeNumber e $uriFrom[user]", 'bold_red');
                    cli::pcl(sip::renderSolution($data));
                    if ($purge) {
                        trunkController::resolveCloseCall($callId, ['purge' => true]);
                        cache::persistExpungeCall($callId, "LIGANDO DEPOIS DE PURGE");


                        /** @var \ObjectProxy $soper */
                        $soper = cache::global()['swooleObjectProxy'];
                        if ($soper->isset($callId)) {
                            $soper->unset($callId);
                        }
                        $socket->tpc->del($callId);
                    }


                    $tpcNewEncoded = json_encode($tpcDataDecoded, JSON_PRETTY_PRINT);


                    if (count($hangups) == 0) {
                        $rpcClient = new \rpcClient();
                        $rpcClient->rpcDelete($callId);
                        $rpcClient->stop();
                        $rpcClient->close();
                        return $socket->tpc->del($callId);
                    }

                    $rpcClient = new \rpcClient();
                    $rpcClient->rpcDelete($callId);
                    $rpcClient->stop();
                    $rpcClient->close();


                    return $socket->tpc->del($callId);
                }
            }
        }
        $socket->tpc->del($callId);


        $tags = cache::global()['tags'];
        $cseq = $headers['CSeq'][0];
        $cseqInt = intval($cseq) + 0;
        $data['headers']['CSeq'][0] = $cseqInt . " BYE";
        $headers['CSeq'][0] = $cseqInt . " BYE";
        //  $socket->tpc->del($callId);


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

        $traces = cache::get('traces');
        if (!is_array($traces)) $traces = [];
        if (!array_key_exists($callId, $traces)) $traces[$callId] = [];
        $traces[$callId][] = [
            'direction' => 'received',
            'receiveFrom' => $from,
            'data' => $data
        ];
        cache::define('traces', $traces);


        /** @var \ObjectProxy $sob */
        $sob = cache::global()['swooleObjectProxy'];
        if ($sob->isset($callId)) {
            /** @var \trunkController $trunkController */
            cli::pcl("Bye controller pelo 5060", 'bold_red');
            $trunkController = $sob->get($callId);
            if (get_debug_type($trunkController) == 'callHandler') {
                /** @var \callHandler $callHandler */
                $callHandler = $trunkController;

                $callHandler->bye();
                $headers['CSeq'][0] = '102 BYE';
                $okBye = renderMessages::respondOptions($headers);
                \callHandler::resolveCloseCall($callId);

                return $socket->sendto($info['address'], $info['port'], $okBye);
            }

            cli::pcl("STATUS ATUAL: " . $trunkController->currentState);
            // buscar qual key tem o valor do username
            $findKey = array_search($uriFrom['user'], $trunkController->originVolumes ?? []);
            if (!$trunkController->isMember($findKey) and !$trunkController->isMember($uriFrom['user'])) {
                print cli::color('red', "[bye] Erro: Usuário $uriFrom[user] não participa da chamada $callId.") . PHP_EOL;
                return $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
            } else {
                print cli::color('green', "[bye] Usuário $uriFrom[user] participa da chamada $callId.") . PHP_EOL;
                $CSeq = sip::csq($trunkController->currentState);
                if ($CSeq == 'REFER') {
                    cli::pcl("STATUS ATUAL: " . $CSeq, 'bold_red');
                    return $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
                } else {
                    cli::pcl("RECEBIDO BYE CONTROLLER", 'bold_red');
                    $messages = renderMessages::respondOptions($headers);
                    $trunkController->socket->sendto($info['address'], $info['port'], $messages);

                    if ($byeNumber == $trunkController->calledNumber) {
                        $trunkController->receiveBye = true;
                        $socket->tpc->del($callId);
                    }

                    return 0;

                }
            }
        }

        if (\trunkController::resolveCloseCall($data['headers']['Call-ID'][0], ['bye' => true, 'recovery' => true])) {
            $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));


            Coroutine::create(function () use ($socket, $data, $info, $callId, $uriFrom) {
                Timer::after(3000, function () use ($socket, $data, $info, $callId, $uriFrom) {
                    $traces = cache::get('traces');
                    $traces[$callId][] = [
                        'direction' => 'send',
                        'sendTo' => sip::renderURI([
                            'user' => $uriFrom['user'],
                            'peer' => ['host' => $info['address'], 'port' => $info['port']]
                        ]),
                        'data' => $data
                    ];
                    cache::define('traces', $traces);
                });
            });


        }


        if (!array_key_exists($callId, cache::global()['dialogProxy'])) {
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
        }


        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
        if (array_key_exists($callId, $rules)) {
            if ($rules[$callId] == $uriFrom['user']) {
                return false;
            }
        }

        if (array_key_exists($callId, $tags)) {
            $tag = $tags[$callId];
            $uriTo = $tag['to'];
            $uriFrom = $tag['from'];
            $data['headers']['From'][0] = sip::renderURI($uriFrom);
            $data['headers']['To'][0] = sip::renderURI($uriTo);
            $data['headers']['Via'] = $tag['vias'];
        }
        $data['headers']['Via'][] = sip::teachVia($uriFrom['user'], $info);


        $getUser = value($bye, 'sip:', '@') ?? '';
        if (str_contains($getUser, '-')) {
            $getUserParts = explode('-', $getUser);
            $getUser = $getUserParts[0] ?? '';
        }
        if (empty($getUser)) {
            print cli::color('red', "[bye] Erro: Usuário não pôde ser extraído do cabeçalho 'BYE'.") . PHP_EOL;
            return false;
        }

        $findToConnection = cache::findConnection(sip::extractURI($headers['To'][0] ?? null)['user']);
        if (!$findToConnection) $findToConnection = cache::findConnection($getUser);

        $emulateDialog = cache::global()['dialogProxy'][$callId];
        if (is_array($emulateDialog)) {
            if (count($emulateDialog) > 2) {
                foreach ($emulateDialog as $key => $v) {
                    if (array_key_exists('exclude', $v)) {
                        unset($emulateDialog[$key]);
                    } elseif (array_key_exists('startBy', $v)) {
                        unset($emulateDialog[$key]);
                    }
                }
                if (is_array($emulateDialog)) {
                    if (count($emulateDialog) == 1) {
                        foreach ($emulateDialog as $u => $ignore) {
                            $findToConnection = cache::findConnection($u);
                        }
                    }
                }
            }
        }


        //  var_dump($data, $getUser, sip::extractURI($headers['To'][0] ?? null)['user'], cache::global()['dialogProxy'][$callId]);


        //unset($data['headers']['Via'][1]);
        cache::persistExpungeCall($data['headers']['Call-ID'][0], "lINHA 161 BYE CONTROLLER");
        if ($findToConnection) {
            $dialogProxy = cache::global()['dialogProxy'];
            if (!is_array($dialogProxy)) $dialogProxy = [];
            if (array_key_exists($callId, $dialogProxy)) {
                $uriTo['user'] = $getUser;
                $data['headers']['From'][0] = sip::renderURI($uriFrom);
                $data['headers']['To'][0] = sip::renderURI($uriTo);


                unset($tags[$callId]);
                cache::define('tags', $tags);

                $rules = cache::global()['rules'];
                unset($rules[$callId]);
                cache::define('rules', $rules);

                $dialogProxy = cache::global()['dialogProxy'];
                unset($dialogProxy[$callId]);
                cli::pcl('UNSET LINE 159');
                cache::define('dialogProxy', $dialogProxy);


                $auths = cache::global()['auth'];
                unset($auths[$callId]);
                cache::define('auth', $auths);

                $u2 = sip::findUserByAddress([$findToConnection['address'], $findToConnection['port']]);
                $uriTo['user'] = $u2;
                $uriFrom['user'] = sip::extractURI($headers['From'][0])['user'];
                $data['headers']['To'][0] = sip::renderURI($uriTo);
                $data['headers']['From'][0] = sip::renderURI($uriFrom);
                $data['methodForParser'] = "BYE sip:$uriTo[user]@{$uriTo['peer']['host']} SIP/2.0";


                $socket->sendto($findToConnection['address'], $findToConnection['port'], sip::renderSolution($data));
            }
        } else {
            print cli::color('green', "[bye] Encaminhando BYE para $uriTo[user]@{$uriTo['peer']['host']}") . PHP_EOL;
            $fromTrunk = sip::getTrunkByUserFromDatabase($uriFrom['user']);
            if (!$fromTrunk) {
                $fromTrunk = sip::getTrunkByUserFromDatabase($uriTo['user']);
            }
            if (!empty($data['headers']['Authorization'])) {
                $username = value($data['headers']['Authorization'][0], 'username="', '"');
                $fromTrunk = sip::getTrunkByUserFromDatabase($username);
            }


            if (!$fromTrunk) {
                return false;
            }
            $uriTo['user'] = $getUser;
            $uriFrom['user'] = $fromTrunk['trunk']['u'];

            $connection = [$fromTrunk['trunk']['h'], 5060];
            unset($tags[$callId]);
            cache::define('tags', $tags);

            $rules = cache::global()['rules'];
            unset($rules[$callId]);
            cache::define('rules', $rules);

            $dialogProxy = cache::global()['dialogProxy'];
            unset($dialogProxy[$callId]);
            cli::pcl('UNSET LINE 205');
            cache::define('dialogProxy', $dialogProxy);

            $auths = cache::global()['auth'];
            if (!empty($auths[$callId])) {
                $data['headers']['Authorization'][0] = $auths[$callId];
            }
            if (!empty($data['headers']['Authorization'])) {
                $method = $data['method'];
                $token = $data['headers']['Authorization'][0];
                $userAuth = value($token, 'username="', '"');
                $user = sip::getTrunkByUserFromDatabase($userAuth);
                if (!$user) {
                    return false;
                }


                $token = sip::generateAuthorizationHeader(
                    $user['trunk']['u'],
                    value($token, 'realm="', '"'),
                    $user['trunk']['p'],
                    value($token, 'nonce="', '"'),
                    explode('@', value($token, 'uri="', '"'))[0] . '@' . $socket->host . ':' . $socket->port,
                    $method
                );
                $data['headers']['Authorization'][0] = $token;
            }


            $uriFrom['user'] = 's';
            $data['headers']['From'][0] = sip::renderURI($uriFrom);
            $data['headers']['To'][0] = sip::renderURI($uriTo);


            $auths = cache::global()['auth'];
            unset($auths[$callId]);
            cache::define('auth', $auths);
            $socket->sendto($connection[0], $connection[1], sip::renderSolution($data));

        }


        return true;

    }
}