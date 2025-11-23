<?php

namespace trunk;

use ArrayObjectDynamic;
use handlers\renderMessages;
use ObjectProxy;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use Random\RandomException;
use sip;
use Swoole\Coroutine;
use trunkController;


cache::define('blackListInvites', []);

class friendInvite
{
    /**
     * Trata as solicitações SIP INVITE
     *
     * Implementa o tratamento de código 491 Request Pending conforme RFC 3261.
     * Quando ocorre uma colisão de re-INVITE (código 491), o UAC deve aguardar um tempo
     * aleatório entre 2.1 e 4 segundos antes de tentar novamente a solicitação.
     *
     * @throws RandomException
     */
    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {
        $uriTo = sip::extractURI($data["headers"]["To"][0]);
        $uriToBackup = $uriTo;
        $originalData = $data;
        $ipReceive = $info["address"];


        $uriFrom = sip::extractURI($data["headers"]["From"][0]);
        $callId = $data["headers"]["Call-ID"][0];
        $callIdSession = $callId;
        $headers = $data["headers"];
        $uri = $uriFrom;
        $uriUser = $uri["user"];
        $startInvite = time();

        swoole_coroutine_defer(function () use ($uriUser) {
            gc_collect_cycles();
            cli::pcl('Ciclo de coleta de lixo concluído para ' . $uriUser, 'gray');

        });


        if (!sip::findUsernameByAddress($ipReceive)) {
            if (sip::findUsername($uriTo["user"])) {
                $receiveCall = sip::findUsername($uriTo["user"]);
                if ($receiveCall["type"] == "central") {
                    return \inviteCentral::resolve($socket, $data, $info);
                }
            }
            if (sip::findUsername($uriFrom["user"])) {
                $receiveCall = sip::findUsername($uriFrom["user"]);
                if ($receiveCall["type"] == "central") {
                    return \inviteCentral::resolve($socket, $data, $info);
                }
            }
            //$data["headers"]["Via"][0] = $data["headers"]["Via"][0] . ";rport";
            // $socket->sendto($info["address"], $info["port"], renderMessages::respond100Trying($data["headers"]));

            // se o from for anonimo, usar o contact
            if (str_contains($uriUser, "anonymous")) {
                $uri = sip::extractURI($data["headers"]["Contact"][0]);
                $uriUser = $uri["user"];
            }
            $traces = cache::get("traces");
            if (!array_key_exists($callIdSession, $traces)) {
                cache::subDefine("traces", $callIdSession, []);
            }
            cache::subJoin('traces', $callIdSession, [
                "direction" => "received",
                "receiveFrom" => trunkController::renderURI(["user" => $uriFrom["user"], "peer" => ["host" => $info["address"], "port" => $info["port"]]]),
                "data" => $data,
            ]);
            cli::pcl("Iniciando callfriend");;

            $details = sip::getTrunkByUserFromDatabase($uriUser);
            $usernameAccount = $details['account']["u"];
            $trunk = $details["trunk"];
            if (empty($headers["Authorization"])) {
                $authHeaders = self::sip401Model($headers);
                cache::subJoin("traces", $callIdSession, [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => ["host" => $info["address"], "port" => $info["port"]],
                    ]),
                    "data" => $authHeaders,
                ]);
                $render = sip::renderSolution($authHeaders);


                return $socket->sendto($info["address"], $info["port"], $render, $info["server_socket"]);
            }
            $token = sip::liteSecurity($data);
            if (!$token) {
                Coroutine::sleep(1);

                $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers));
                return trunkController::resolveCloseCall($callIdSession, ["bye" => true, "recovery" => true]);
            }
        } else {
            $findData = sip::findUsernameByAddress($ipReceive);
            $usernameAccount = $findData["u"];
            $uriUser = $usernameAccount;
            $details = sip::getTrunkByUserFromDatabase($usernameAccount);
            $trunk = $details["trunk"];


            if (sip::findUsername($uriTo["user"])) {
                $receiveCall = sip::findUsername($uriTo["user"]);
                if ($receiveCall["type"] == "central") {
                    return \inviteCentral::resolve($socket, $data, $info);
                }
            }
            if (sip::findUsername($uriFrom["user"])) {
                $receiveCall = sip::findUsername($uriFrom["user"]);
                if ($receiveCall["type"] == "central") {
                    return \inviteCentral::resolve($socket, $data, $info);
                }
            }
        }


        $balance = (int)$details["account"]["b"];
        $rcc = (int)$details["account"]["rcc"];
        $limitCallsUser = (int)$details["account"]["lmc"];
        $trunkId = $details['account']['t'];


        if (cache::countCallsByUser($uriFrom["user"], $socket) >= $limitCallsUser) {
            Coroutine::sleep(1);
            $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 429, "Limite de {$details["account"]["lmc"]} excedido - Contrate mais com 5512982800746"));
            return trunkController::resolveCloseCall($callIdSession, ["bye" => true, "recovery" => true]);
        }
        if ($balance <= 0) {
            Coroutine::sleep(1);
            return $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 403, "Seu saldo acabou"));
        }


        $findConnection = cache::findConnection($uriTo["user"]);
        if (!$findConnection) {
            $offModel = renderMessages::respond486Busy($headers, "Destino desconectado");
            return $socket->sendto($info["address"], $info["port"], $offModel, $info["server_socket"]);
        }


        if (!empty($details["account"]["rprix"])) {
            $prefix = $details["account"]["rprix"];
            $user = $uriToBackup["user"];

            // Se o user começar com o prefixo, remove ele
            if (str_starts_with($user, $prefix)) {
                $uriToBackup["user"] = substr($user, strlen($prefix));
            }
            $uriTo = $uriToBackup;
            $data["headers"]["To"][0] = trunkController::renderURI($uriTo);
            $headers["To"] = [sip::renderURI($uriTo)];
        }
        $addPrefix = $details["account"]["aprix"] ?? "";
        if (strlen($addPrefix) > 0) {
            if (!str_starts_with($uriTo["user"], $addPrefix)) {
                $data["headers"]["To"][0] = trunkController::renderURI($uriTo);
                $headers["To"] = [sip::renderURI($uriTo)];
                $uriTo["user"] = $addPrefix . $uriTo["user"];
                $uriToBackup["user"] = $addPrefix . $uriToBackup["user"];

            }
        }
        $tryModel = renderMessages::respond100Trying($data['headers']);
        $traces = cache::get("traces");
        $traces[$callIdSession][] = [
            "direction" => "send",
            "sendTo" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => ["host" => $info["address"], "port" => $info["port"]],
            ]),
            "data" => sip::parse($tryModel),
        ];
        cache::define("traces", $traces);
        $socket->sendto($info["address"], $info["port"], $tryModel, $info["server_socket"]);
        $mediaSetup = false;
        $srs = [];
        try {
            $rpClient = new \rpcClient();
            cli::pcl("RPC Client conectado com sucesso para callId: $callId", 'green');
        } catch (\Exception $e) {
            cli::pcl("ERRO ao conectar RPC Client: " . $e->getMessage(), 'red');
            error_log("RPC Client Error - CallID: $callId - " . $e->getMessage());
            // Continuar sem RPC se falhar
            $rpClient = null;
        }


        /** @var ObjectProxy $sop */
        $sop = cache::get('swooleObjectProxy');
        if (!array_key_exists('swooleCSeqProxy', $GLOBALS)) {
            cache::define('swooleCSeqProxy', new ObjectProxy(new ArrayObjectDynamic([])));
        }
        $scp = cache::get('swooleCSeqProxy');
        if (!$sop->isset($callId)) {
            $mediaIp = explode(" ", $data["sdp"]["c"][0])[2];
            $mediaPort = explode(" ", $data["sdp"]["m"][0])[1];
            $ssrc = null;
            foreach ($data['sdp']['a'] as $row) {
                if (str_starts_with($row, "ssrc:")) {
                    $ssrce = explode(" ", $row);
                    foreach ($ssrce as $key => $value) {
                        if (str_contains($value, 'ssrc:')) {
                            $ssrc = explode(":", $value)[1];
                            break 2;
                        }
                    }
                    break;
                }
            }
            $codecs = explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
            $srs[$ssrc] = ['address' => $mediaIp, 'port' => $mediaPort];


            // se não tiver codec 101
            $needRemove = false;
            if (!in_array("101", $codecs)) {
                $needRemove = true;
            }
            $staticSupportCodecs = array_keys(trunkController::$supportedCodecStatic);
            $codecs = array_merge($codecs, $staticSupportCodecs);
            $codecs = array_unique($codecs);
            if ($needRemove) {
                $codecs = array_filter($codecs, function ($row) {
                    return $row != "101";
                });
            }

            $dialogProxy = cache::get('dialogProxy');


            $dialogProxy[$callId][$uriFrom["user"]] = [
                "proxyPort" => $socket->port,
                "proxyIp" => network::getLocalIp(),
                "peerPort" => $info["port"],
                "peerIp" => $info["address"],
                "startBy" => true,
                "codec" => $codecs[0],
                "startedAt" => time(),
                "trunk" => $trunk["n"],
                "price" => $trunk["d"],
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
            ];

            cache::define("dialogProxy", $dialogProxy);


            $tck = [
                $details['account']['t'] => [
                    'u' => null,
                    'p' => null,
                    'n' => $uriTo["user"],
                    'h' => $findConnection["address"],
                    'po' => $findConnection["port"],
                    'dm' => $findConnection['address'],
                    'r' => false,
                    'rp' => '',
                    'ap' => '',
                    'nf' => true,
                    'mt' => '320',
                    'mr' => '3',
                    'd' => 0,
                    'codecs' => implode(',', $codecs),
                    'b' => 1,
                    'd2' => 0,
                    'bi' => 'inactive',
                    'c' => $uriTo["user"],
                    'dmedia' => false,
                    'id' => $details['account']['t'],
                ]
            ];
            $trunk = $tck[$details['account']['t']];




            foreach ($tck as $id => $rw) {
                $tck[$id]['id'] = $id;
            }
            $trunks = array_filter($tck, function ($row) use ($trunk) {
                $hash = md5("$row[u]:$row[p]@$row[h]");
                $hash1 = md5("$trunk[u]:$trunk[p]@$trunk[h]");
                if ($hash !== $hash1) {
                    return true;
                }
                return false;
            });

            if ($details["account"]["dc"]) {
                $trunks = [$trunk, ...$trunks];
            } else {
                $trunks = [$trunk];
            }
            if ($details["account"]["rc"]) {
                shuffle($trunks);
            }


            $masterRetry = 0;
            $masterRetryMax = 3;


            $ltid = false;
            foreach ($trunks as $trunk) {
                $trunkId = $trunk['id'];


                $masterRetry++;
                if (array_key_exists("mr", $trunk)) {
                    $masterRetryMax = $trunk["mr"];
                }
                if ($masterRetry > $masterRetryMax + 1) {
                    print cli::cl("bold_red", "Master retry excedido");
                    if ($trunkId == $ltid) {
                        cache::persistExpungeCall($callIdSession);
                        break;
                    } else {
                        $ltid = $trunkId;
                        $masterRetry = 0;
                    }
                }


                $username = $trunk["u"];
                $password = $trunk["p"];
                $host = $trunk["h"];
                if (array_key_exists("po", $trunk)) {
                    $trunkPort = $trunk["po"] ?? 5060;
                } else {
                    $trunkPort = 5060;
                }
                if ($trunkPort < 1024) $trunkPort = 5060;


                if (array_key_exists("rp", $trunk)) {
                    if (str_starts_with($uriTo["user"], $trunk["rp"])) {
                        $uriTo["user"] = substr($uriTo["user"], strlen($trunk["rp"]));
                    }
                }
                if (array_key_exists("ap", $trunk)) {
                    // checar se o prefixo já existe
                    if (!str_starts_with($uriTo["user"], $trunk["ap"])) {
                        $prefix = $trunk["ap"];
                    } else {
                        $prefix = "";
                    }
                } else {
                    $prefix = "";
                }
                if (array_key_exists("dm", $trunk)) {
                    $domain = $trunk["dm"];
                } else {
                    $domain = false;
                }
                $sop->set($callId, new trunkController($username, $password, $host, $trunkPort, $domain));
                try {
                    $scp->set($callId, $data["headers"]["CSeq"][0]);
                } catch (\Exception $e) {
                    print cli::cl("bold_red", "Erro ao setar CSeq: " . $e->getMessage());
                }


                /** @var trunkController $trunkController */
                $trunkController = $sop->get($callId);

                if (!empty($ssrc)) $trunkController->ssrc = $ssrc;
                else {
                    $ssrc = $trunkController->ssrc;
                    $srs[$ssrc] = ['address' => $mediaIp, 'port' => $mediaPort];
                    unset($srs['']);
                }


                $register = false;
                for ($n = $trunk["mr"]; $n--;) {
                    if ($username and $password) {
                        if (!$trunkController->register()) {
                            print cli::color("bold_red", "Falha ao registrar! $username@$host") . PHP_EOL;
                        } else {
                            print cli::color("bold_green", "Registrado com sucesso! $username@$host") . PHP_EOL;
                            $register = true;
                            break;
                        }
                    } else {
                        $register = true;
                        break;
                    }
                }
                if (!$register) {
                    print cli::color("bold_red", "Falha ao registrar! $username@$host") . PHP_EOL;
                    continue;
                }

                $trunkController->setCallId($callId);


                $destUser = $uriTo['user'];


                $trunkController->setCallerId($uriFrom["user"]);


                if (!array_key_exists("mr", $trunk)) {
                    $maxRetry = 5;
                } else {
                    $maxRetry = $trunk["mr"];
                }
                $retry = 0;
                $oldCallId = false;

                $uriTo = $uriToBackup;
                if (strlen($prefix) > 0) {
                    $uriTo["user"] = $prefix . $uriTo["user"];
                    $data["headers"]["To"][0] = trunkController::renderURI($uriTo);
                    $headers["To"] = [sip::renderURI($uriTo)];
                }

                if (array_key_exists('codecs', $trunk)) {
                    if (count(explode(',', $trunk['codecs'])) >= 1) {
                        $cdd = explode(',', $trunk['codecs']);
                        $fixed = '101';
                        if (!in_array($fixed, $cdd)) {
                            //$cdd[] = $fixed;
                        }
                        $trunkController->defineCodecs($cdd);
                    } else {
                        $trunkController->defineCodecs($codecs);
                    }
                }
                $trunkController->defineCodecs();


                $trunkController->host = network::getLocalIp();
                $trunkController->port = $socket->port;
                $trunkController->domain = network::getLocalIp();


                $trunkController->mapLearn = [];
                $codecs = explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                foreach ($data["sdp"]["a"] as $row) {
                    if (str_starts_with($row, "rtpmap:")) {
                        $row = explode(" ", $row)[1];
                        $trunkController->mountLineCodecSDP($row);
                    }
                }
                $cdd = explode(',', $details['trunk']['codecs']);

                foreach ($cdd as $row) {
                     $trunkController->mountLineCodecSDP($row);
                }




                $modelInvite = $trunkController->modelInvite($uriTo["user"]);
                $modelInvite['headers']['To'][0] = sip::renderURI([
                    "user" => $uriTo["user"],
                    'peer' => [
                        'host' => $findConnection['address'],
                    ]
                ]);
                $modelInvite['headers']['Via'][0] = "SIP/2.0/UDP $trunkController->localIp:$socket->port;branch=z9hG4bK64d" . bin2hex(random_bytes(8));
                $modelInvite['headers']['CSeq'][0] = $data["headers"]["CSeq"][0];
                $modelInvite['headers']['From'][0] = $headers["From"][0];
                //$modelInvite['headers']['To'][0] = $headers["To"];


                $trunkController->csq = explode(" ", $data["headers"]["CSeq"][0])[0];


                $trunkController->host = $findConnection['address'];
                $trunkController->port = $findConnection['port'];


                cli::pcl(sip::renderSolution($modelInvite));
                $trunkController->saveGlobalInfo('codecsModel', $codecs);


                $tag = bin2hex(random_bytes(10));
                $baseHeaders = [
                    "Via" => $headers["Via"],
                    "Max-Forwards" => ["70"],
                    "From" => $headers["From"],
                    "To" => [
                        sip::renderURI([
                            "user" => $uri["user"],
                            "peer" => ["host" => network::getLocalIp()],
                            "additional" => ["tag" => $tag],
                        ]),
                    ],
                    "Call-ID" => $headers["Call-ID"],
                    "CSeq" => $headers["CSeq"],
                    "Contact" => [
                        sip::renderURI([
                            "user" => $uri["user"],
                            "peer" => [
                                "host" => network::getLocalIp(),
                                'port' => $socket->port
                            ],
                        ]),
                    ],
                ];
                $uriFromParsed = sip::extractURI($baseHeaders["From"][0]);
                $uriFromParsed["peer"]["host"] = $info["address"];
                $modelRing = [
                    "method" => "180",
                    "methodForParser" => "SIP/2.0 180 Ringing",
                    "headers" => [
                        "Via" => $baseHeaders["Via"],
                        "Max-Forwards" => ["70"],
                        "From" => [sip::renderURI($uriFromParsed)],
                        "To" => $data["headers"]["From"],
                        "Call-ID" => $headers["Call-ID"],
                        "CSeq" => $headers["CSeq"],
                        "Contact" => [
                            sip::renderURI([
                                "user" => $uriTo["user"],
                                "peer" => [
                                    "host" => network::getLocalIp(),
                                    'port' => $socket->port
                                ],
                            ]),
                        ],
                    ],
                ];
                $uriMr = sip::extractURI($data["headers"]["From"][0]);
                $version = 'IP4';
                $localIp = network::getLocalIp();

                $codecs = explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));


                $parsedCodec = trunkController::getSDPModelCodecs($data['sdp']['a']);




                $renderOK = [
                    "method" => "200",
                    "methodForParser" => "SIP/2.0 200 OK",
                    "headers" => [
                        "Via" => $headers["Via"],
                        "Max-Forwards" => ["70"],
                        "From" => [
                            sip::renderURI([
                                "user" => sip::extractURI($data["headers"]["From"][0])["user"],
                                "peer" => [
                                    "host" => network::getLocalIp(),
                                    "port" => $socket->port
                                ],
                                "additional" => ["tag" => $uriMr["additional"]["tag"]],
                            ]),
                        ],
                        "To" => $data["headers"]["From"],
                        "Call-ID" => $data["headers"]["Call-ID"],
                        "CSeq" => $modelRing["headers"]["CSeq"],
                        "Allow" => ["INVITE, ACK, CANCEL, BYE, OPTIONS, REFER, NOTIFY"],
                        "Supported" => ["replaces, gruu"],
                        "Server" => [cache::global()["interface"]["server"]["serverName"]],
                        "Contact" => [
                            sip::renderURI([
                                "user" => $uriTo["user"],
                                "peer" => [
                                    'host' => $info['address'],
                                    'port' => $info['port'],
                                ],
                            ])
                        ],
                        "Content-Type" => ["application/sdp"],
                    ],
                    "sdp" => [
                        "v" => ["0"],
                        "o" => [$trunkController->ssrc . " 0 0 IN IP4" . " " . network::getLocalIp()],
                        "s" => [cache::global()["interface"]["server"]["serverName"]],
                        "c" => ["IN $version " . $localIp],
                        "t" => ["0 0"],
                        "m" => ["audio " . $trunkController->audioReceivePort . " RTP/AVP $parsedCodec[codecMediaLine]"],
                        "a" => [
                            ...$parsedCodec["codecRtpMap"],
                            'sendrecv'
                        ],
                    ],
                ];


                $uriContact = sip::extractURI($data["headers"]["Contact"][0]);
                $byeClient = [
                    "method" => "BYE",
                    "methodForParser" => "BYE sip:{$uriContact["user"]}@{$uriContact["peer"]["host"]}:{$uriContact["peer"]["port"]} SIP/2.0",
                    "headers" => [
                        "Via" => $headers["Via"],
                        "From" => $renderOK["headers"]["To"],
                        "To" => $renderOK["headers"]["From"],
                        "Max-Forwards" => ["70"],
                        "Call-ID" => $renderOK["headers"]["Call-ID"],
                        "CSeq" => [((int)explode(" ", $data["headers"]["CSeq"][0])[0]) . " BYE"],
                        "Content-Length" => ["0"],
                        "Contact" => $renderOK["headers"]["Contact"],
                        "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE"],
                    ],
                ];
                if (array_key_exists("Authorization", $data["headers"])) {
                    $byeClient["headers"]["Authorization"] = $data["headers"]["Authorization"];
                }


                $contact = sip::extractURI($data["headers"]["Contact"][0]);
                $lineb = str_replace([
                    '<', '>',
                ], "", $data["headers"]["Contact"][0]);
                $byeContact = [
                    "method" => "BYE",
                    "methodForParser" => "BYE $lineb SIP/2.0",
                    "headers" => [
                        "Via" => $data["headers"]["Via"],
                        "From" => $renderOK["headers"]['To'],
                        "To" => $renderOK["headers"]['From'],
                        "Max-Forwards" => ["70"],
                        "Call-ID" => $data["headers"]["Call-ID"],
                        "CSeq" => [intval($data['headers']['CSeq'][0]) + rand(1000, 9999) . " BYE"],
                        "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
                        "Contact" => [sip::renderURI([
                            "user" => $uriToBackup['user'],
                            'peer' => [
                                "host" => network::getLocalIp(),
                                "port" => $socket->port,
                            ],
                        ])],
                        "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE"],
                    ],
                ];


                $mapped = trunkController::getSDPModelCodecs($data['sdp']['a']);
                $codecMapper = [
                    $mapped['preferredCodec']['pt'] => strtoupper(
                        implode('/', [
                            $mapped['preferredCodec']['name'],
                            $mapped['preferredCodec']['rate']
                        ])
                    ),
                    $parsedCodec['preferredCodec']['pt'] => strtoupper(
                        implode('/', [
                            $parsedCodec['preferredCodec']['name'],
                            $parsedCodec['preferredCodec']['rate']
                        ])
                    ),
                    $mapped['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $mapped['dtmfCodec']['rate']
                    ]),
                    $parsedCodec['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $parsedCodec['dtmfCodec']['rate']
                    ]),
                ];


                $proxyMedia = [
                    'proxyIp' => network::getLocalIp(),
                    'proxyPort' => $trunkController->audioReceivePort,
                    'peerIp' => $mediaIp,
                    'peerPort' => $mediaPort,
                    'base' => $uriFrom["user"],
                    'polo' => $uriTo['user'],
                    'group' => $callId,
                    'cid' => $callId,
                    'codec' => "{$mapped['preferredCodec']['pt']}",
                    'ssrc' => $trunkController->ssrc,
                    "codecMapper" => $codecMapper,


                    'rcc' => true
                ];
                //$trunkController->proxyMedia($proxyMedia);




                $socket->tpc->set($callId, ['data' => json_encode([
                    'info' => $info,
                    'codecsModel' => $codecs,
                    'callId' => $callId,
                    'refer' => false,
                    'data' => $renderOK,
                    'friendCall' => $info,
                    'proxyMedia' => $proxyMedia,
                    'trunkData' => $trunk,
                    'dialogProxy' => $dialogProxy[$callId],
                    'trunkSocketBind' => $findConnection['port'],
                    'audioReceivePort' => $trunkController->audioReceivePort,
                    'hangups' => [
                        $uriFrom["user"] => [
                            'model' => $byeClient,
                            'info' => [
                                'host' => $info["address"],
                                'port' => $info["port"],
                            ],
                            'displayName' => $uriFrom["user"],
                            'isOriginator' => true,
                            'isUriFrom' => true,
                        ],
                        $contact["user"] => [
                            'model' => $byeContact,
                            'isContact' => true,
                            'info' => [
                                'host' => $info["address"],
                                'port' => $info["port"],
                            ],
                            'displayName' => $contact["user"],
                            'isOriginator' => true,
                        ],
                    ]
                ], JSON_PRETTY_PRINT)]);


                $modelInvite['headers']['Contact'][0] = $data["headers"]["Contact"][0];
                $modelInvite['headers']['Route'][0] = sip::renderURI([
                    "user" => $uriTo["user"],
                    "peer" => [
                        'host' => network::getLocalIp(),
                        'port' => $socket->port
                    ]
                ]);
                $modelInvite['headers']['Record-Route'][0] = sip::renderURI([
                    "user" => $uriTo["user"],
                    "peer" => [
                        'host' => network::getLocalIp(),
                        'port' => $socket->port
                    ],
                    'extra' => ['lr']
                ]);


              //  var_dump($findConnection);


                return $socket->sendto($findConnection['address'], $findConnection['port'], sip::renderSolution($modelInvite));


            }

        } else {

            return $socket->sendto($info["address"], $info["port"], renderMessages::respond486Busy($data["headers"]), $info["server_socket"]);


        }

        print cli::cl("bold_red", sip::renderSolution($data));
        $sop->unset($callId);
        $rpClient->rpcDelete($callId);
        $rpClient->close();
        return true;
    }

    public static function sip401Model($headers): array
    {
        return [
            "method" => "401",
            "methodForParser" => "SIP/2.0 401 Unauthorized",
            "headers" => [
                "Via" => $headers["Via"],
                "Max-Forwards" => !empty($headers["Max-Forwards"]) ?? ["70"],
                "From" => $headers["From"],
                "To" => $headers["To"],
                "Call-ID" => $headers["Call-ID"],
                "Content-Length" => [0],
                "WWW-Authenticate" => ['Digest realm="' . cache::global()["interface"]["server"]["serverName"] . '", nonce="' . md5(time()) . '", algorithm=MD5'],
                "CSeq" => $headers["CSeq"],
            ],
        ];
    }
}

