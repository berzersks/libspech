<?php

use handlers\renderMessages;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;

class inviteCentral
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'];
        $uri = sip::extractURI($headers['To'][0]);
        $uriFrom = sip::extractURI($headers['From'][0]);
        $callId = $headers['Call-ID'][0];
        $callIdSession = $callId;
        $ipReceive = $info["address"];
        cache::subJoin("traces", $callIdSession, [
            "direction" => "received",
            "receiveFrom" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => $data,
        ]);
        cli::pcl(sip::renderSolution($data));
        if (!sip::findUsernameByAddress($ipReceive)) {
            if (empty($headers["Authorization"])) {
                $authHeaders = \trunk\invite::sip401Model($headers);
                $render = sip::renderSolution($authHeaders);
                cache::subJoin("traces", $callIdSession, [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                    ]),
                    "data" => sip::parse($render),
                ]);
                return $socket->sendto($info["address"], $info["port"], $render, $info["server_socket"]);
            }
            $token = sip::liteSecurity($data);
            if (!$token) {
                \Swoole\Coroutine::sleep(1);
                $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers));
                return trunkController::resolveCloseCall($callIdSession, ["bye" => true, "recovery" => true]);
            }

            // Definir variáveis após autenticação bem-sucedida
            $uriUser = $uriFrom["user"];
            $details = sip::getTrunkByUserFromDatabase($uriUser);
            $trunk = $details["trunk"];
        } else {
            $findData = sip::findUsernameByAddress($ipReceive);
            $usernameAccount = $findData["u"];
            $uriUser = $usernameAccount;
            $details = sip::getTrunkByUserFromDatabase($usernameAccount);
            $trunk = $details["trunk"];
        }
        $tag = bin2hex(random_bytes(10));
        $baseHeaders = [
            'Via' => $headers['Via'],
            'Max-Forwards' => ['70'],
            'From' => $headers['From'],
            'To' => [sip::renderURI([
                'user' => $uri['user'],
                'peer' => ['host' => $socket->host],
                'additional' => ['tag' => $tag],
            ])],
            'Call-ID' => $headers['Call-ID'],
            'CSeq' => $headers['CSeq'],
            'Contact' => [sip::renderURI([sip::renderURI([
                'user' => $uri['user'],
                'peer' => ['host' => $socket->host],
            ])])],
        ];
        $tryModel = renderMessages::respond100Trying($baseHeaders);
        cache::subJoin("traces", $callIdSession, [
            "direction" => "send",
            "sendTo" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => sip::parse($tryModel),
        ]);
        $socket->sendto($info['address'], $info['port'], $tryModel, $info['server_socket']);
        $modelRing = [
            'method' => '180',
            'methodForParser' => "SIP/2.0 180 Ringing",
            'headers' => [
                'Via' => $baseHeaders['Via'],
                'Max-Forwards' => ['70'],
                'From' => $baseHeaders['From'],
                'To' => [sip::renderURI([
                    'user' => $uri['user'],
                    'peer' => ['host' => $socket->host],
                    'additional' => ['tag' => bin2hex(random_bytes(10))],
                ])],
                'Call-ID' => $headers['Call-ID'],
                'CSeq' => $headers['CSeq'],
                'Contact' => [sip::renderURI([
                    'user' => $uriFrom['user'],
                    'peer' => ['host' => $socket->host],
                ])],
            ],
        ];
        $socket->sendto($info['address'], $info['port'], sip::renderSolution($modelRing), $info['server_socket']);
        cache::subJoin("traces", $callIdSession, [
            "direction" => "send",
            "sendTo" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => $modelRing,
        ]);
        $receiveIp = $info['address'];
        $freePort = network::getFreePort();
        $receivePort = explode(' ', $data['sdp']['m'][0])[1];
        $codecs = explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
        $staticSupportCodecs = array_keys(trunkController::$supportedCodecStatic);
        $codecs = array_merge($codecs, $staticSupportCodecs);
        $codecs = array_unique($codecs);
        $modelCodecs = trunkController::getModelCodecs([
            8,
            101,
        ]);
        /** @var ObjectProxy $sop */
        /** @var callHandler $trunkController */
        $sop = cache::global()['swooleObjectProxy'];
        $sop->set($callId, new callHandler($callId));
        $trunkController = $sop->get($callId);
        $trunkData = $details['trunk'];
        $phone = new trunkController($trunkData['u'] ?? null, $trunkData['p'] ?? null, $trunkData['h'] ?? null, $trunkData['po'] ?? null, $trunkData['d'] ?? null);
        for ($n = 15; $n--;) {
            $trunkController->registerDtmfCallback((string)$n, function (callHandler $trunkController) use ($n) {
                print cli::cl("red", "DTMF: {$n}");
            });
        }
        if (!empty($details['account']['u']) and !empty($details['account']['p'])) {
            if (!$phone->register(1)) {
                cli::pcl("Não foi possível conectar ao trunk {$trunkData['u']}");
            } else {
                cli::pcl("Conectado ao trunk {$trunkData['u']}");
            }
        }
        $mask = $details["account"]["c"] ?? '';
        $bi = $details["account"]["bi"] ?? 'default';
        $destUser = $uri['user'];
        $originUser = $uriFrom['user'];
        $isNational = str_starts_with($destUser, '55');
        if (str_contains($mask, '[COUNTRY]')) {
            $mask = str_replace('[COUNTRY]', $isNational ? '55' : '', $mask);
        }
        if (str_contains($mask, '[DDD]')) {
            $destDdd = $isNational ? substr($destUser, 2, 2) : '00';
            $mask = str_replace('[DDD]', $destDdd, $mask);
        }
        $mask = preg_replace_callback('/[xd]/i', fn() => random_int(0, 9), $mask);
        $mask = preg_replace('/\D/', '', $mask);
        if ($bi === 'inactive') {
            if (empty($mask) || strlen($mask) < 2) {
                $mask = $originUser;
            }
            $phone->setCallerId($mask);
        } elseif ($bi === 'ddd') {
            $bina = $mask;
            $destDdd = $isNational ? substr($destUser, 2, 2) : '00';
            $binaDdd = str_starts_with($bina, '55') ? substr($bina, 2, 2) : substr($bina, 0, 2);
            $useDdd = $destDdd !== $binaDdd ? $destDdd : $binaDdd;
            $endDigits = substr($bina, -9);
            $mask = $isNational ? '55' . $useDdd . $endDigits : substr($bina, -15);
            $phone->setCallerId($mask);
        } else {
            $phone->setCallerId(sip::loadBestCallerId($destUser));
        }
        $phone->call($uri['user']);
        $f = 'manage/ivr/makita.wav';
        $trunkController->declareAudio($f, $codecs[0]);
        $durationString = trunkController::getWavDuration($f);
        $segs = sscanf($durationString, "%d:%d:%d")[0] * 3600 + sscanf($durationString, "%d:%d:%d")[1] * 60 + sscanf($durationString, "%d:%d:%d")[2];
        $trunkController->setTimeout($trunk['mt']);
        $trunkController->audioPortServer = $freePort;
        $trunkController->audioReceivePort = $receivePort;
        $trunkController->username = $uri['user'];
        $trunkController->addListener($receiveIp, $receivePort);
        $renderOK = [
            'method' => '200',
            'methodForParser' => "SIP/2.0 200 OK",
            'headers' => [
                'Via' => $headers['Via'],
                'Call-ID' => $modelRing['headers']['Call-ID'],
                'Max-Forwards' => ['70'],
                'From' => $modelRing['headers']['From'],
                'To' => $modelRing['headers']['To'],
                'CSeq' => $modelRing['headers']['CSeq'],
                'Allow' => ['INVITE, ACK, CANCEL, BYE, OPTIONS, REFER, NOTIFY'],
                'Supported' => ['replaces, gruu'],
                'Server' => [cache::global()['interface']['server']['serverName']],
                'Contact' => [sip::renderURI([
                    'user' => $uri['user'],
                    'peer' => [
                        'host' => $socket->host,
                        'port' => $trunkController->socket->getsockname()['port'],
                    ],
                ])],
                'Content-Type' => ['application/sdp'],
            ],
            "sdp" => [
                "v" => ["0"],
                "o" => [$uri['user'] . " 0 0 IN IP4 " . network::getLocalIp()],
                "s" => [cache::global()["interface"]["server"]["serverName"]],
                "c" => ["IN IP4 " . network::getLocalIp()],
                "t" => ["0 0"],
                "m" => ["audio {$freePort} RTP/AVP {$modelCodecs['codecMediaLine']}"],
                "a" => [...$modelCodecs["codecRtpMap"]],
            ],
        ];
        $dialog = [
            $uriFrom["user"] => [
                "proxyPort" => $freePort,
                "proxyIp" => $trunkController->localIp,
                "peerPort" => $freePort,
                "peerIp" => $receiveIp,
                "startBy" => true,
                "codec" => $codecs[0],
                "startedAt" => time(),
                "trunk" => "DID",
                "username" => $uriFrom["user"],
                "headers" => $headers,
                "preCancel" => [
                    "address" => $info["address"],
                    "port" => $info["port"],
                    "render" => sip::renderSolution([
                        "method" => "CANCEL",
                        "methodForParser" => "CANCEL sip:" . sip::extractURI($data["headers"]["To"][0])["user"] . "@{$info["address"]}:{$info["port"]} SIP/2.0",
                        "headers" => [
                            "Via" => $headers["Via"],
                            "Max-Forwards" => ["70"],
                            "From" => $headers["From"],
                            "To" => $headers["To"],
                            "Call-ID" => $headers["Call-ID"],
                            "CSeq" => $headers["CSeq"],
                            "Content-Length" => [0],
                        ],
                    ]),
                ],
            ],
            $uri['user'] => [
                "proxyPort" => $freePort,
                "proxyIp" => $trunkController->localIp,
                "peerPort" => 'internal',
                "peerIp" => network::getLocalIp(),
                "startBy" => false,
                "codec" => $codecs[0],
                "trunk" => 'DID',
                "startedAt" => time(),
                "headers" => $renderOK["headers"],
                "username" => $uri['user'],
            ],
        ];
        $lineEscaped = str_replace([
            '<',
            '>',
        ], '', $headers['Contact'][0]);
        $byeClient = [
            "method" => "BYE",
            "methodForParser" => "BYE " . $lineEscaped . " SIP/2.0",
            "headers" => [
                "Via" => $headers["Via"],
                "From" => $renderOK["headers"]["To"],
                "To" => $headers["From"],
                "Max-Forwards" => ["70"],
                "Call-ID" => $headers['Call-ID'],
                "CSeq" => [rand(21234, 73524) . " BYE"],
                "Content-Length" => ["0"],
                "Contact" => $renderOK["headers"]["Contact"],
                "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE"],
            ],
        ];
        $remoteIp = $info['address'];
        $phone->onRinging(function (trunkController $phone) use (&$trunkController, $receiveIp, $receivePort, $codecs, $modelCodecs, $callId, $uri, $info, $socket, $renderOK, $dialog, $freePort, $byeClient, $f, $headers, $uriFrom) {
            $phone->callActive = true;
            $phone->receiveMedia();
            $version = 'IP4';
            $localIp = network::getLocalIp();
            $sdp = [
                "v" => ["0"],
                "o" => [$trunkController->ssrc . " 0 0 IN {$version} " . $localIp],
                "s" => [cache::global()["interface"]["server"]["serverName"]],
                "c" => ["IN {$version} " . $localIp],
                "t" => ["0 0"],
                "m" => ["audio " . $freePort . " RTP/AVP {$modelCodecs['codecMediaLine']}"],
                "a" => [
                    ...$modelCodecs["codecRtpMap"],
                    'sendrecv',
                ],
            ];
            $modelRing = [
                "method" => "183",
                "methodForParser" => "SIP/2.0 183 Session Progress",
                "headers" => [
                    "Via" => $headers["Via"],
                    "Max-Forwards" => ["70"],
                    "From" => $headers["From"],
                    "To" => [sip::renderURI([
                        "user" => $uri["user"],
                        "peer" => ["host" => $socket->host],
                        "additional" => ["tag" => bin2hex(random_bytes(10))],
                    ])],
                    "Call-ID" => $headers["Call-ID"],
                    "CSeq" => $headers["CSeq"],
                    "Contact" => [sip::renderURI([
                        "user" => $uri["user"],
                        "peer" => [
                            "host" => $socket->host,
                            "port" => $socket->port,
                        ],
                    ])],
                    "Content-Type" => ["application/sdp"],
                ],
                'sdp' => $sdp,
            ];
            $trunkController->declareAudio($f, $codecs[0]);
            $trunkController->addListener($info["address"], $receivePort);
            $solution = sip::renderSolution($modelRing);
            $socket->sendto($info["address"], $info["port"], $solution, $info['server_socket']);
            $trunkController->play();
        });
        $phone->onAnswer(function (trunkController $phone) use ($uriUser, $uriFrom, &$trunkController, $details, $receiveIp, $receivePort, $codecs, $modelCodecs, $callId, $uri, $info, $socket, $renderOK) {


            $socket->sendto($info['address'], $info['port'], sip::renderSolution($renderOK), $info['server_socket']);
            $trunkController->addListener($receiveIp, $receivePort);
            $queueKeys = [];
            for ($n = 15; $n--;) {
                $trunkController->registerDtmfCallback((string)$n, function (callHandler $trunkController) use ($n, &$queueKeys) {
                    $queueKeys[] = $n;
                });
            }


            $trunkId = $details['account']['t'];
            $pricePerMinute = cache::get('trunks')[$trunkId]['d'];
            $pricePerMinuteTrunk = cache::get('trunks')[$trunkId]["d2"] ?? cache::get('trunks')[$trunkId]["d"];
            $lastTime = time();

            // adicionar débito a cada 10 segundos
            cli::pcl("$uriFrom[user] ligou para $uri[user] - $trunkId - $pricePerMinuteTrunk", 'yellow');
            $discountTick = 1;
            $idTimerDebit = \Swoole\Timer::tick($discountTick * 1000, function ($idTimerDebit) use (&$trunkController, $uriUser, $discountTick, &$phone, &$queueKeys, &$lastTime, $pricePerMinute, $pricePerMinuteTrunk, $trunkId) {
                if (is_null($trunkController)) {
                    return \Swoole\Timer::clear($idTimerDebit);
                }
                if (is_null($phone)) {
                    return \Swoole\Timer::clear($idTimerDebit);
                }
                if (!$phone->callActive) {
                    return \Swoole\Timer::clear($idTimerDebit);
                }


                $lastTime = time();
                $debit = ($pricePerMinute / 60) * $discountTick;
                $debitTrunk = ($pricePerMinuteTrunk / 60) * $discountTick;
                cache::subJoin('debits', $uriUser, $debit);
                cache::subJoin('debitsTrunk', $trunkId, $debitTrunk);

            });

            $idTimer = \Swoole\Timer::tick(200, function ($idTimer) use (&$trunkController, &$phone, &$queueKeys) {
                if (is_null($trunkController)) {
                    return \Swoole\Timer::clear($idTimer);
                }
                if ($trunkController->receiveBye) {
                    $phone->bye(true);
                    $trunkController->bye(true);
                    \Swoole\Timer::clear($idTimer);
                    $phone->__destruct();
                    $trunkController->__destruct();
                    cache::get('swooleObjectProxy')->unset($trunkController->callId);
                    return false;
                }
                if (empty($queueKeys)) {
                    return false;
                }
                $eventPress = array_shift($queueKeys);
                $phone->send2833($eventPress, 0, 1);
                return true;
            });
            $phone->callActive = true;
            $phone->sendSilence($receiveIp, $receivePort);


            $trunkController->stopMedia();
            $phone->receiveMedia();
            cli::pcl("Chamada atendida - iniciando ponte de áudio");
            cli::pcl("Ponte de áudio estabelecida entre {$info['address']}:{$receivePort} e trunk");
            $channel = new bcg729Channel();
            $phone->onReceiveAudio(function ($audioData, $peer) use (&$trunkController, $codecs, &$channel, $info, $receivePort) {
                $parsed = new rtpc($audioData);
                $receivedCodec = $parsed->getCodec();
                if ($receivedCodec == $codecs[0]) {
                    return $trunkController->mediaSocket->sendto($info['address'], $receivePort, $audioData);
                } else {
                    $decode = match ($parsed->getCodec()) {
                        18 => $channel->decode($parsed->payloadRaw),
                        0 => decodePcmuToPcm($parsed->payloadRaw),
                        8 => decodePcmaToPcm($parsed->payloadRaw),
                        default => $parsed->payloadRaw,
                    };
                    $encode = match ($codecs[0]) {
                        18 => $channel->encode($decode),
                        0 => encodePcmToPcmu($decode),
                        8 => encodePcmToPcma($decode),
                        default => $decode,
                    };
                    $parsed->setPayloadType($codecs[0]);
                    $rebuild = $parsed->build($encode);
                    return $trunkController->mediaSocket->sendto($info['address'], $receivePort, $rebuild);
                }
            });
        });
        $phone->onHangup(function (trunkController $phone) use (&$trunkController, $receiveIp, $receivePort, $codecs, $modelCodecs, $callId, $uri, $info, $socket, $renderOK, $dialog, $freePort, $byeClient, $f, $headers, $uriFrom) {
            cli::pcl("Chamada encerrada 366");
            $phone->callActive = false;
            $trunkController->stopMedia();
            $byeRed = sip::renderSolution($byeClient);
            $socket->sendto($info['address'], $info['port'], $byeRed, $info['server_socket']);
            $trunkController->bye(true);
            $phone->bye(true);
            $trunkController->__destruct();
            $phone->__destruct();
            unset($trunkController);
            unset($phone);
        });
        $phone->onFailed(function ($call) use (&$trunkController, $receiveIp, $receivePort, $codecs, $modelCodecs, $callId, $uri, $info, $socket, $renderOK, $dialog, $freePort, $byeClient, $f, $headers, $uriFrom) {
            cli::pcl("A CHAMADA FALHOU: " . json_encode($call));
            //$tkch->bye(true);
            //$tkch->__destruct();
        });
        $trunkController->onHangup(function (callHandler $tkch) use (&$trunkController, $receiveIp, $receivePort, $codecs, $modelCodecs, $callId, $uri, $info, $socket, $renderOK, $dialog, $freePort, $byeClient, $f, $headers, $uriFrom) {
            cli::pcl("Chamada encerrada 385");
            $tkch->bye(true);
            $tkch->__destruct();
        });


        if (array_key_exists("Authorization", $data["headers"])) {
            $byeClient["headers"]["Authorization"] = $data["headers"]["Authorization"];
        }
        $trunkController->registerByeRecovery($byeClient, $info, $socket);











        cache::subDefine('dialogProxy', $callId, $dialog);
        $currentSecond = date("s");
        $lastSecond = cache::get("lastSecond");
        if ($lastSecond < 1) {
            cache::define("lastSecond", $currentSecond);
            cache::define("callsLastSecond", 0);
            $lastSecond = $currentSecond;
        }
        if ($currentSecond !== $lastSecond) {
            cache::define("callsLastSecond", 0);
            cache::define("lastSecond", $currentSecond);
        }
        cache::increment("totalCalls");
        cache::increment("callsLastSecond");
        $cps = cache::get("callsLastSecond");
        $maxCps = cache::get("cpsMax");
        if ($cps > $maxCps) {
            print cli::cl("red", "calls last second: {$cps} > max cps: {$maxCps}");
            cache::define("cpsMax", $cps);
        }
        cache::subJoin("traces", $callIdSession, [
            "direction" => "send",
            "sendTo" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => $renderOK,
        ]);
        return true;
    }

    public static function volumeAverage(string $pcm): float
    {
        $minLength = 160;
        if (empty($pcm)) {
            return 0.0;
        }
        if (strlen($pcm) < $minLength) {
            return 0.1;
        }
        $pcm = strlen($pcm) > $minLength ? substr($pcm, 0, $minLength) : $pcm;
        $soma = 0;
        $numSamples = 80;
        $maxValue = 32768.0;
        for ($i = 0; $i < $minLength; $i += 2) {
            $sample = unpack('s', substr($pcm, $i, 2))[1];
            $soma += $sample * $sample;
        }
        $rms = sqrt($soma / $numSamples);
        $normalized = $rms / $maxValue;
        return max(1, min(100, round($normalized * 100, 2)));
    }
}