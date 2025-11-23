<?php

namespace trunk;

use ArrayObjectDynamic;
use callHandler;
use Exception;
use handlers\renderMessages;
use inviteCentral;
use ObjectProxy;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use rpcClient;
use ServerSocket;
use sip;
use sip\InviteLockManager;
use Swoole\Coroutine\Socket;
use trunkController;

cache::define('blackListInvites', []);
InviteLockManager::initialize();

class invite
{
    public static function resolve(ServerSocket $socket, $data, $info): bool
    {
        $table = $socket->tbu;
        $database = [];
        $techs = [];
        foreach ($table as $key => $rowHard) {
            $row = json_decode($rowHard['data'], true);
            $database[] = $row;
            if (array_key_exists('prefix', $row)) {
                if (!empty($row['prefix'])) {
                    $prefix = $row['prefix'];
                    if (array_key_exists('phosts', $row)) {
                        $ips = explode(',', $row['phosts'] ?? '');
                        foreach ($ips as $ip) {
                            $techs[$ip][$prefix] = $row['u'];
                        }
                    }
                }
            }
        }
        cache::define("database", $database);
        $uriTo = sip::extractURI($data["headers"]["To"][0]);
        $uriToBackup = $uriTo;
        $originalData = $data;
        $ipReceive = $info["address"];
        $uriFrom = sip::extractURI($data["headers"]["From"][0]);
        $callId = $data["headers"]["Call-ID"][0];
        $callIdSession = $callId;
        $lockMetadata = [
            'from_user' => $uriFrom["user"],
            'to_user' => $uriTo["user"],
            'ip' => $info["address"],
            'port' => $info["port"],
        ];
        if (!InviteLockManager::lockInvite($callId, $lockMetadata)) {
            $socket->sendto($info["address"], $info["port"], renderMessages::e491RequestPending($data["headers"], 'Request já está sendo processado'));
            return false;
        }
        $reusableSocket = InviteLockManager::getReusableSocket($callId);
        if ($reusableSocket) {
        }
        swoole_coroutine_defer(function () use ($callId) {
            InviteLockManager::unlockInvite($callId);
            gc_collect_cycles();
        });
        $headers = $data["headers"];
        $uri = $uriFrom;
        $uriUser = $uri["user"];
        $startInvite = time();
        $callId = $headers['Call-ID'][0];
        $via = $headers['Via'][0];
        if (str_contains($via, 'rport')) {
            $viaParams = [];
            if (preg_match('/SIP\/2\.0\/UDP\s+([^;]+)(.*)/i', $via, $matches)) {
                $hostPort = $matches[1];
                $params = $matches[2];
                if (preg_match('/rport(?!=)/i', $params)) {
                    $params = preg_replace('/rport(?!=)/i', 'rport=' . $info['port'], $params);
                }
                if (!str_contains($params, 'received')) {
                    $params .= ';received=' . $info['address'];
                }
                $headers['Via'][0] = 'SIP/2.0/UDP ' . $hostPort . $params;
                $data['headers']['Via'][0] = $headers['Via'][0];
            }
        }
        if (sip::findUsername($uriTo["user"])) {
            $receiveCall = sip::findUsername($uriTo["user"]);
            if ($receiveCall["type"] == "central") {
                return inviteCentral::resolve($socket, $data, $info);
            }
        }
        if (sip::findUsername($uriFrom["user"])) {
            $receiveCall = sip::findUsername($uriFrom["user"]);
            if ($receiveCall["type"] == "central") {
                return inviteCentral::resolve($socket, $data, $info);
            }
        }
        $authenticatedByCredentials = false;
        $usernameAccount = null;
        $trunk = null;
        $details = null;
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
            "receiveFrom" => trunkController::renderURI([
                "user" => $uriFrom["user"],
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => $data,
        ]);
        try {
            $details = sip::getTrunkByUserFromDatabase($uriUser);
            if ($details && isset($details['account']["u"])) {
                $usernameAccount = $details['account']["u"];
                $trunk = $details["trunk"];
                if (empty($headers["Authorization"])) {
                    $authHeaders = self::sip401Model($headers);
                    cache::subJoin("traces", $callIdSession, [
                        "direction" => "send",
                        "sendTo" => trunkController::renderURI([
                            "user" => $uriFrom["user"],
                            "peer" => [
                                "host" => $info["address"],
                                "port" => $info["port"],
                            ],
                        ]),
                        "data" => $authHeaders,
                    ]);
                    $render = sip::renderSolution($authHeaders);
                    return $socket->sendto($info["address"], $info["port"], $render, $info["server_socket"]);
                }
                $token = sip::liteSecurity($data);
                if ($token) {
                    $authenticatedByCredentials = true;
                }
            }
        } catch (Exception $e) {
        }
        if (!$authenticatedByCredentials) {
            $originalUriTo = $uriTo;
            $originalUriFrom = $uriFrom;
            $techPrefixFound = false;
            $techPrefixUsed = '';
            if (array_key_exists($info['address'], $techs)) {
                $subAccountsByTech = $techs[$info['address']];
                foreach ($subAccountsByTech as $techPrefix => $usernameAttached) {
                    if (!empty($techPrefix) && strpos($uriTo['user'], $techPrefix) === 0) {
                        cli::pcl('Techprefix encontrado: ' . $techPrefix . ' para usuário: ' . $usernameAttached);
                        $usernameAccount = $usernameAttached;
                        $details = sip::getTrunkByUserFromDatabase($usernameAccount);
                        $trunk = $details["trunk"];
                        $techPrefixFound = true;
                        $techPrefixUsed = $techPrefix;
                        $uriTo['user'] = substr($uriTo['user'], strlen($techPrefix));
                        $uriToBackup['user'] = $uriTo['user'];
                        $data['headers']['To'][0] = sip::renderURI($uriTo);
                        $headers['To'] = [sip::renderURI($uriTo)];
                        $uriUser = $usernameAccount;
                        break;
                    }
                }
                if (!$techPrefixFound) {
                    $findData = sip::findUsernameByAddress($ipReceive);
                    if ($findData) {
                        $usernameAccount = $findData["u"];
                        $uriUser = $usernameAccount;
                        $details = sip::getTrunkByUserFromDatabase($usernameAccount);
                        $trunk = $details["trunk"];
                        if (!empty($details["account"]["prefix"])) {
                            $accountPrefix = $details["account"]["prefix"];
                            if (strpos($uriTo['user'], $accountPrefix) === 0) {
                                $uriTo['user'] = substr($uriTo['user'], strlen($accountPrefix));
                                $uriToBackup['user'] = $uriTo['user'];
                                $data['headers']['To'][0] = sip::renderURI($uriTo);
                                $headers['To'] = [sip::renderURI($uriTo)];
                            }
                            if (strpos($uriFrom['user'], $accountPrefix) === 0) {
                            }
                        }
                    }
                }
            } else {
                $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers, 'IP não autorizado para techprefix.'));
                return trunkController::resolveCloseCall($callIdSession, [
                    "bye" => true,
                    "recovery" => true,
                ]);
            }
            if (!$details) {
                $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers, 'Falha na autenticação por techprefix.'));
                return trunkController::resolveCloseCall($callIdSession, [
                    "bye" => true,
                    "recovery" => true,
                ]);
            }
            if ($techPrefixFound) {
                cli::pcl("Techprefix '{$techPrefixUsed}' removido. URI original: {$originalUriTo['user']} -> Nova URI: {$uriTo['user']}", 'green');
            }
        }
        $balance = (int)$details["account"]["b"];
        $rcc = (int)$details["account"]["rcc"];
        $limitCallsUser = (int)$details["account"]["lmc"];
        $trunkId = $details['account']['t'];
        if (cache::countCallsByUser($uriFrom["user"], $socket) >= $limitCallsUser) {
            $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 429, "Limite de {$details["account"]["lmc"]} excedido - Contrate mais com 5512982800746"));
            return trunkController::resolveCloseCall($callIdSession, [
                "bye" => true,
                "recovery" => true,
            ]);
        }
        if (str_contains(sip::renderSolution($data), 'sendonly')) {
            return $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 403, "Não é possível enviar som"));
        }


        if ($balance <= 0) {
            return $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 403, "Seu saldo acabou - Recarregue sua conta"));
        }
        $minimumCallCost = $trunk["d"] / 60 * 30;
        // Custo para 30 segundos
        if ($balance < $minimumCallCost) {
            return $socket->sendto($info["address"], $info["port"], renderMessages::baseResponse($headers, 403, "Saldo insuficiente - Mínimo R\$ " . number_format($minimumCallCost, 4, ',', '.')));
        }
        cli::pcl("SALDO VERIFICADO - User: {$uriUser}, Saldo atual: {$balance}, Custo/min: {$trunk["d"]}", 'green');
        if (!empty($details["account"]["rprix"])) {
            $prefix = $details["account"]["rprix"];
            $user = $uriToBackup["user"];
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
                "peer" => [
                    "host" => $info["address"],
                    "port" => $info["port"],
                ],
            ]),
            "data" => sip::parse($tryModel),
        ];
        cache::define("traces", $traces);
        $socket->sendto($info["address"], $info["port"], $tryModel, $info["server_socket"]);
        $mediaSetup = false;
        $srs = [];
        try {
            $rpClient = new rpcClient();
        } catch (Exception $e) {
            error_log("RPC Client Error - CallID: {$callId} - " . $e->getMessage());
            $rpClient = null;
        }
        /** @var ObjectProxy $sop */
        $sop = cache::get('swooleObjectProxy');
        if (!array_key_exists('swooleCSeqProxy', $GLOBALS)) {
            cache::define('swooleCSeqProxy', new ObjectProxy(new ArrayObjectDynamic([])));
        }
        $scp = cache::get('swooleCSeqProxy');
        if ($sop->isset($callId)) {
            InviteLockManager::unlockInvite($callId);
            $socket->sendto($info['address'], $info['port'], renderMessages::baseResponse($headers, 487, "Call already exists"));
            return false;
        }
        $statsData = $socket->statsCalls->get('data');
        $socket->statsCalls->set('data', [
            'callsLastSecond' => $statsData['callsLastSecond'],
            'totalCalls' => $statsData['totalCalls'],
            'maxActive' => $statsData['maxActive'],
            'cpsMax' => $statsData['cpsMax'],
            'cts' => ($statsData['cts'] <= 1 ?? 0) + 1,
        ]);
        if (!$sop->isset($callId)) {
            $mediaIp = explode(" ", $data["sdp"]["c"][0])[2];
            $mediaPort = explode(" ", $data["sdp"]["m"][0])[1];
            $ssrc = null;

            $mediaIp = $info["address"];
            $mediaIp = network::resolveAddress($mediaIp);
            $tmpSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
            $tmpSocket->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
            $tmpSocket->connect($info['address'], $info['port'], 1);
            $respondPort = $tmpSocket->getsockname()['port'];


            $modelOptions = renderMessages::generateModelOptions($data["headers"], $respondPort);
            $tmpSocket->sendto($info["address"], $info["port"], sip::renderSolution($modelOptions));
            $rrc = $tmpSocket->recvfrom($pp, 1);
            if ($rrc) {
                cli::pcl($rrc, 'yellow');
                $parse = sip::parse($rrc);
                $viasx = sip::extractVia($parse['headers']['Via'][0]);
                var_dump($viasx);
                $mediaIp = $pp['address'];

            }
            $tmpSocket->close();


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
            $srs[$ssrc] = [
                'address' => $mediaIp,
                'port' => $mediaPort,
            ];
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
            $parsedCodec = trunkController::getSDPModelCodecs($data['sdp']['a']);
            $pfc = $parsedCodec['preferredCodec'];
            $dialogProxy = cache::get('dialogProxy');
            $dialogProxy[$callId][$uriFrom["user"]] = [
                "proxyPort" => 0,
                "proxyIp" => network::getLocalIp(),
                "peerPort" => $mediaPort,
                "peerIp" => $mediaIp,
                "startBy" => true,
                "codec" => "{$pfc['name']} " . round($pfc['rate'] / 1000) . "kHz",
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
            $trunksFile = json_decode(file_get_contents('trunks.json'), true);
            if (empty($trunksFile)) {
            } else {
                $tck = $trunksFile;
                cache::define("trunks", $tck);
            }
            $tck = cache::get("trunks");
            foreach ($tck as $id => $rw) {
                $tck[$id]['id'] = $id;
            }
            $trunks = array_filter($tck, function ($row) use ($trunk) {
                $hash = md5("{$row['u']}:{$row['p']}@{$row['h']}");
                $hash1 = md5("{$trunk['u']}:{$trunk['p']}@{$trunk['h']}");
                if ($hash !== $hash1) {
                    return true;
                }
                return false;
            });
            if ($details["account"]["dc"]) {
                $trunks = [
                    $trunk,
                    ...$trunks,
                ];
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
                    $masterRetryMax = (int)$trunk["mr"];
                }
                if ($masterRetry > $masterRetryMax + 1) {
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
                if ($trunkPort < 1024) {
                    $trunkPort = 5060;
                }
                if (array_key_exists("rp", $trunk)) {
                    if (str_starts_with($uriTo["user"], $trunk["rp"])) {
                        $uriTo["user"] = substr($uriTo["user"], strlen($trunk["rp"]));
                    }
                }
                if (array_key_exists("ap", $trunk)) {
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
                } catch (Exception $e) {
                }
                /** @var trunkController $trunkController */
                $trunkController = $sop->get($callId);
                InviteLockManager::storeReusableSocket($callId, $trunkController->socket, [
                    'trunk_host' => $trunkController->host,
                    'trunk_port' => $trunkController->port,
                    'username' => $username,
                    'timestamp' => time(),
                ]);
                if (!empty($ssrc)) {
                    $trunkController->ssrc = $ssrc;
                } else {
                    $ssrc = $trunkController->ssrc;
                    $srs[$ssrc] = [
                        'address' => $mediaIp,
                        'port' => $mediaPort,
                    ];
                    unset($srs['']);
                }
                $register = false;
                for ($n = $trunk["mr"]; $n--;) {
                    if ($username and $password) {
                        if (!$trunkController->register(1)) {
                            print cli::color("bold_red", "Falha ao registrar! {$username}@{$host}") . PHP_EOL;
                        } else {
                            print cli::color("bold_green", "Registrado com sucesso! {$username}@{$host}") . PHP_EOL;
                            $register = true;
                            break;
                        }
                    } else {
                        $register = true;
                        break;
                    }
                }
                if (!$register) {
                    print cli::color("bold_red", "Falha ao registrar! {$username}@{$host}") . PHP_EOL;
                }
                foreach ($data['sdp']['a'] as $row) {
                    if (str_starts_with($row, "rtpmap:")) {
                        $trunkController->mountLineCodecSDP(explode(" ", $row)[1]);
                    }
                }
                $originalSDP = trunkController::getSDPModelCodecs($data['sdp']['a']);
                if (empty($originalSDP['codecMediaLine'])) {
                    $learnPtForce = explode(" ", $data['sdp']['m'][0])[0];
                    $learnPt = trunkController::$supportedCodecStatic[$learnPtForce];
                    $learnName = value($learnPt[0], ' ', '/');
                    $learnRate = explode('/', $learnPt[0])[1];
                    $trunkController->mountLineCodecSDP("{$learnName}/{$learnRate}");
                    $trunkController->codecRtpMap = [];
                    $codecs = array_keys($trunkController->mapLearn);
                    foreach ($codecs as $codec) {
                        foreach ($trunkController->mapLearn[$codec] as $media) {
                            $trunkController->codecRtpMap[] = $media;
                        }
                    }
                    $originalSDP = trunkController::getSDPModelCodecs([...$trunkController->codecRtpMap]);
                }
                $trunkController->setCallId($callId);
                $mask = $details["account"]["c"] ?? '';
                $bi = $details["account"]["bi"] ?? 'default';
                $destUser = $uriTo['user'];
                var_dump($destUser);
                $originUser = $uriFrom['user'];
                $isNational = str_starts_with($destUser, '55');
                if (str_contains($mask, '[COUNTRY]')) {
                    $mask = str_replace('[COUNTRY]', $isNational ? '55' : '', $mask);
                }
                if (str_contains($mask, '[DDD]')) {
                    $destDdd = $isNational ? substr($destUser, 2, 2) : '00';
                    $mask = str_replace('[DDD]', $destDdd, $mask);
                }
                $mask = preg_replace_callback('/[xd]/i', fn() => random_int(1, 9), $mask);
                $mask = preg_replace('/\D/', '', $mask);
                if ($bi === 'inactive') {
                    if (empty($mask) || strlen($mask) < 2) {
                        $mask = $originUser;
                    }
                    $trunkController->setCallerId($mask);
                } elseif ($bi === 'ddd') {
                    $bina = $mask;
                    $destDdd = $isNational ? substr($destUser, 2, 2) : '00';
                    $binaDdd = str_starts_with($bina, '55') ? substr($bina, 2, 2) : substr($bina, 0, 2);
                    $useDdd = $destDdd !== $binaDdd ? $destDdd : $binaDdd;
                    $endDigits = substr($bina, -9);
                    $mask = $isNational ? '55' . $useDdd . $endDigits : substr($bina, -15);
                    $trunkController->setCallerId($mask);
                } else {
                    $trunkController->setCallerId(sip::loadBestCallerId($destUser));
                }
                if (!array_key_exists("mr", $trunk)) {
                    $maxRetry = 5;
                } else {
                    $maxRetry = $trunk["mr"];
                }
                $retry = 0;
                $oldCallId = false;
                $uriTo = $uriToBackup;
                $cdd = explode(',', $trunk['codecs']);
                $trunkController->mapLearn = [];
                foreach ($cdd as $cd) {
                    $trunkController->mountLineCodecSDP($cd);
                }
                var_dump($uriTo['user']);
                $modelInvite = $trunkController->modelInvite($uriTo["user"], $trunk['ap'], $cdd);
                foreach ($data['sdp']['a'] as $row) {
                    if (str_starts_with($row, "rtpmap:")) {
                        $trunkController->mountLineCodecSDP(explode(" ", $row)[1]);
                    }
                }
                var_dump($trunk['codecs']);
                if (array_key_exists('dmedia', $details['account'])) {
                    if ($details['account']['dmedia'] == 'yes') {
                        $modelInvite['sdp'] = $data["sdp"];
                        $modelInvite['sdp']['c'] = ["IN IP4 " . $mediaIp];
                    }
                }
                $traces = cache::get("traces");
                $traces[$callIdSession][] = [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriTo["user"],
                        "peer" => [
                            "host" => $trunkController->host,
                            "port" => $trunkController->port,
                        ],
                    ]),
                    "data" => $modelInvite,
                ];
                cache::define("traces", $traces);

                $trunkController->declareVolume("{$mediaIp}:{$mediaPort}", $uriFrom["user"], explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL))[0]);
                cli::pcl("$trunk[n] Ligando para {$uriTo['user']} - {$trunk['n']} - {$callId}", 'yellow');
                $authSent = false;
                $lastSequence = 0;
                $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($modelInvite));
                $rec = $trunkController->socket->recvfrom($peer, 1);
                for ($al = 5; $al--;) {
                    if (!$rec) {
                        $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($modelInvite));
                        $rec = $trunkController->socket->recvfrom($peer, 1);
                    }
                    if ($rec) {
                        $receive = sip::parse($rec);


                        $pv = sip::extractVia($receive["headers"]["Via"][0]);
                        $needAuth = $trunkController->checkAuthHeaders($receive["headers"]);
                        $intSequence = (int)explode(" ", $receive["headers"]["CSeq"][0])[0];
                        if ($intSequence !== $lastSequence) {
                            if ($needAuth) {
                                if ($authSent) {
                                    $needAuth = false;
                                }
                            }
                        }
                        if ($needAuth and !$authSent) {
                            if ($needAuth == "Proxy-Authorization") {
                                $valueHeader = $receive["headers"]["Proxy-Authenticate"][0];
                                if (str_contains($valueHeader, 'realm="')) {
                                    $realm = value($valueHeader, 'realm="', '"');
                                } else {
                                    $realm = "asterisk";
                                }
                                if (str_contains($valueHeader, 'nonce="')) {
                                    $nonce = value($valueHeader, 'nonce="', '"');
                                } else {
                                    $nonce = $trunkController->nonce;
                                }
                                if (str_contains($valueHeader, 'qop="')) {
                                    $qop = value($valueHeader, 'qop="', '"');
                                } else {
                                    $qop = "auth";
                                }
                                $isStale = str_contains($valueHeader, 'stale=true');
                                if ($isStale || !$nonce) {
                                    continue;
                                }
                                $modelInvite["headers"][$needAuth] = [sip::generateResponseProxy($trunkController->username, $trunkController->password, $realm, $nonce, sprintf("sip:%s@%s", $uriTo["user"], $trunkController->host), "INVITE", $qop)];
                            }
                            if ($needAuth == "Authorization") {
                                $wwwAuthenticate = $receive["headers"]["WWW-Authenticate"][0];
                                $nonce = value($wwwAuthenticate, 'nonce="', '"');
                                $realm = value($wwwAuthenticate, 'realm="', '"');
                                $isStale = str_contains($wwwAuthenticate, 'stale=true');
                                if ($isStale || !$nonce) {
                                    break;
                                }
                                $modelInvite["headers"][$needAuth] = [sip::generateAuthorizationHeader($trunkController->username, $realm, $trunkController->password, $nonce, sprintf("sip:%s@%s", $uriTo["user"], $trunkController->localIp), "INVITE")];
                            }
                            $trunkController->csq = $trunkController->csq + 1;
                            $modelInvite["headers"]["CSeq"] = [sprintf("%d INVITE", $trunkController->csq)];
                            $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($modelInvite));
                            $traces = cache::get("traces");
                            $traces[$callIdSession][] = [
                                "direction" => "send",
                                "sendTo" => trunkController::renderURI([
                                    "user" => $uriTo["user"],
                                    "peer" => [
                                        "host" => $trunkController->host,
                                        "port" => $trunkController->port,
                                    ],
                                ]),
                                "data" => $modelInvite,
                            ];
                            $authSent += true;
                            cache::define("traces", $traces);
                        }
                        break;
                    }
                }
                $tag = md5(random_bytes(10));
                //$options = $trunkController->modelOptions();


                $from = sip::extractURI($headers["From"][0]);

                $baseHeaders = [
                    "Via" => $headers["Via"],
                    "Max-Forwards" => ["70"],
                    "From" => [sip::renderURI([
                        'user' => $from["user"],
                        'peer' => [
                            'host' => $pv['received'] ?? network::getLocalIp(),
                            'port' => $socket->port,
                            'extra' => $from['peer']['extra'],
                        ],
                        'additional' => $from['additional'],
                    ])],
                    "To" => [sip::renderURI([
                        "user" => $uri["user"],
                        "peer" => ["host" => $pv['received'] ?? network::getLocalIp(), 'port' => $socket->port],
                        "additional" => ["tag" => $tag],
                    ])],
                    "Call-ID" => $headers["Call-ID"],
                    "CSeq" => $headers["CSeq"],
                    "Contact" => [sip::renderURI([
                        "user" => $uri["user"],
                        "peer" => [
                            "host" => $pv['received'] ?? network::getLocalIp(),
                            'port' => $socket->port,
                        ],
                    ])],
                ];
                for (; ;) {
                    $inviteElapsed = time() - $startInvite;
                    if ($inviteElapsed > 25) {
                        $errModel = renderMessages::e503Un($data["headers"], "Sem progresso por " . intval($inviteElapsed) . " segundos");
                        $socket->sendto($info["address"], $info["port"], $errModel);
                        cache::persistExpungeCall($callIdSession);
                        $trunkController->stopProxyMedia();
                        $trunkController->bye();
                        break;
                    }
                    if ($trunkController->receiveBye) {
                        $trunkController->bye();
                        $trunkController->callActive = false;
                        $trunkController->error = true;
                        $trunkController->receiveBye = true;
                        trunkController::resolveCloseCall($callId);
                        $traces = cache::get("traces");
                        $traces[$callIdSession][] = [
                            "direction" => "send",
                            "sendTo" => trunkController::renderURI([
                                "user" => $uriFrom["user"],
                                "peer" => [
                                    "host" => $info["address"],
                                    "port" => $info["port"],
                                ],
                            ]),
                            "data" => sip::parse(renderMessages::e503Un($data["headers"])),
                        ];
                        cache::define("traces", $traces);
                        $socket->sendto($info["address"], $info["port"], renderMessages::e503Un($data["headers"]), $info["server_socket"]);
                        $sop->unset($callId);
                        cache::unset("traces", $callIdSession);
                        cache::unset("traces", $callId);
                        InviteLockManager::unlockInvite($callId);
                        if ($rpClient) {
                            try {
                                $rpClient->rpcDelete($callId);
                            } catch (Exception $e) {
                                error_log("RPC Delete Error - CallID: {$callId} - " . $e->getMessage());
                            }
                            try {
                                $rpClient->close();
                            } catch (Exception $e) {
                            }
                        }
                        return false;
                    }
                    $peer = [];
                    try {
                        $rec = $trunkController->socket->recvfrom($peer, 1);
                    } catch (Exception $e) {
                        continue;
                    }
                    if (!$rec) {
                        continue;
                    }
                    $receive = sip::parse($rec);
                    if (!array_key_exists("Call-ID", $receive["headers"])) {
                        if (!array_key_exists("i", $receive["headers"])) {
                            continue;
                        }
                        $receive["headers"]["Call-ID"] = $receive["headers"]["i"];
                    }
                    if ($receive['method'] == 'OPTIONS') {
                        $respond = renderMessages::respondOptions($receive["headers"]);
                        $trunkController->socket->sendto($peer["address"], $peer["port"], $respond);
                        continue;
                    }
                    if ($receive["headers"]["Call-ID"][0] !== $trunkController->callId) {
                        $respond = renderMessages::respondOptions($receive["headers"]);
                        $trunkController->socket->sendto($peer["address"], $peer["port"], $respond);
                    }
                    cache::subJoin("traces", $callIdSession, [
                        "direction" => "received",
                        "receiveFrom" => $receive["headers"]["From"][0],
                        "data" => $receive,
                    ]);
                    if ($receive["method"] === "491") {
                        $render491 = renderMessages::respondAckModel($modelInvite["headers"]);
                        $socket->sendto($trunkController->host, $trunkController->port, $render491);
                        $baseDelay = min(pow(2, $retry) * 0.1, 2.0);
                        $jitter = random_int(0, 100) / 100 * 0.1;
                        $delay = $baseDelay + $jitter;
                        $delay = random_int(2100, 4000) / 1000;
                        continue;
                    }
                    if ($authSent > 3) {
                        $retry = $maxRetry;
                    }
                    $discards = [
                        '480',
                        'CANCEL',
                        'BYE',
                        '486',
                        '487',
                        '488',
                        '500',
                        '600',
                        '603',
                    ];
                    if (in_array($receive['method'], $discards)) {
                        $trunkController->bye();
                        $trunkController->callActive = false;
                        $trunkController->error = true;
                        $trunkController->receiveBye = true;
                        trunkController::resolveCloseCall($callId);
                        $traces = cache::get("traces");
                        $traces[$callIdSession][] = [
                            "direction" => "send",
                            "sendTo" => trunkController::renderURI([
                                'user' => $details["account"]["u"],
                                'peer' => [
                                    'host' => $info["address"],
                                    'port' => $info["port"],
                                ],
                            ]),
                            "data" => sip::parse(renderMessages::respondUserNotFound($data["headers"], 'Nao existe')),
                        ];
                        cache::define("traces", $traces);
                        $sop->unset($callId);
                        cache::persistExpungeCall($callId, "INVITE ROUTES 404 LINE 506");
                        InviteLockManager::unlockInvite($callId);
                        $rpClient->rpcDelete($callId);
                        $rpClient->close();
                        return $socket->sendto($info["address"], $info["port"], renderMessages::e503Un($data["headers"], 'Nao existe'), $info["server_socket"]);
                    }
                    $loggs = [
                        "400",
                        'BYE',
                        'CANCEL',
                        "404",
                        "403",
                        "503",
                        "486",
                        "480",
                        '407',
                        "408",
                        "487",
                        "488",
                        "500",
                        "600",
                        "603",
                    ];
                    if (in_array($receive["method"], $loggs)) {
                        $retry++;
                        if ($retry > $maxRetry) {
                            cache::persistExpungeCall($callIdSession);
                            $trunkController->bye(false);
                            $trunkController->callActive = false;
                            print cli::cl("bold_red", " error {$receive['headers']['CSeq'][0]} xligando para {$uriTo['user']} - {$receive['methodForParser']} {$receive["headers"]["Call-ID"][0]}");
                            if ($details["account"]["dc"]) {
                                break;
                            } else {
                                break 2;
                            }
                        } else {
                            $baseDelay = min(pow(2, $retry) * 0.1, 2.0);
                            $jitter = random_int(0, 100) / 100 * 0.1;
                            $delay = $baseDelay + $jitter;
                            if ($receive["method"] === "491") {
                                $delay = random_int(2100, 4000) / 1000;
                            }
                            if ($receive["method"] === "403") {
                                $retry = $maxRetry;
                            }
                            if ($retry == $maxRetry) {
                                break;
                            } else {
                                continue 2;
                            }
                        }
                    }
                    if ($receive["method"] == "OPTIONS") {
                        $respond = renderMessages::respondOptions($receive["headers"]);
                        $trunkController->socket->sendto($peer["address"], $peer["port"], $respond);
                        continue;
                    }
                    if (str_contains($details["account"]["c"], "x")) {
                        for ($i = 0; $i < strlen($details["account"]["c"]); $i++) {
                            if (strtolower($details["account"]["c"][$i]) == "x") {
                                $details["account"]["c"][$i] = random_int(0, 9);
                            }
                        }
                    }
                    if ($details["account"]["bi"] !== "active") {
                        $trunkController->setCallerId($details["account"]["c"]);
                    } else {
                        $trunkController->setCallerId(sip::loadBestCallerId($uriTo["user"]));
                    }
                    if (!array_key_exists("Call-ID", $receive["headers"])) {
                        if (array_key_exists("i", $receive["headers"])) {
                            $receive["headers"]["Call-ID"] = $receive["headers"]["i"];
                        } else {
                            continue;
                        }
                    }
                    if ($receive["headers"]["Call-ID"][0] !== $trunkController->callId) {
                        continue;
                    }
                    if (in_array($receive["method"], $trunkController->progressCodes)) {
                        $trunkController->progressLevel++;
                        $uriFromParsed = sip::extractURI($baseHeaders["From"][0]);
                        $uriFromParsed["peer"]["host"] = $info["address"];
                        if (array_key_exists("sdp", $receive)) {
                            if (!array_key_exists("c", $receive["sdp"])) {
                                $resolved = sip::renderSolution($receive);
                                file_put_contents("sdp_error.sdp", $resolved);
                                $receive['method'] = '180';
                            }
                        }
                        if ($receive["method"] == "180") {
                            $modelRing = [
                                "method" => "180",
                                "methodForParser" => "SIP/2.0 180 Ringing",
                                "headers" => [
                                    "Via" => $baseHeaders["Via"],
                                    "Max-Forwards" => ["70"],
                                    "From" => [sip::renderURI($uriFromParsed)],
                                    "To" => [sip::renderURI([
                                        "user" => sip::extractURI($originalData["headers"]["To"][0])["user"],
                                        "peer" => ["host" => network::getLocalIp()],
                                        "additional" => ["tag" => md5(random_bytes(10))],
                                    ])],
                                    "Call-ID" => $headers["Call-ID"],
                                    "CSeq" => $headers["CSeq"],
                                    "Contact" => [sip::renderURI([
                                        "user" => $uriTo["user"],
                                        "peer" => [
                                            "host" => network::getLocalIp(),
                                            'port' => $socket->port,
                                        ],
                                    ])],
                                ],
                            ];
                            $socket->sendto($info["address"], $info["port"], sip::renderSolution($modelRing), $info["server_socket"]);
                        }
                        if ($receive["method"] == "183" and array_key_exists('sdp', $receive)) {
                            $trunkController->receiveBye = false;
                            $trunkController->callActive = true;
                            $version = "IP4";
                            if (str_contains($mediaIp, ":")) {
                                $version = "IP6";
                            }
                            $localIp = cache::get('myIpAddress');
                            if ($version == "IP6") {
                                $localIp = ip2long($localIp);
                            }
                            $codecsModel = [$codecs[0]];
                            if (!array_key_exists('sdp', $receive)) {
                                file_put_contents("sdp_error.sdp", sip::renderSolution($receive));
                            }
                            $codecsReceive = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                            $countCodecs = count($codecsReceive);
                            if ($countCodecs > 1) {
                                $codecsModel[] = $codecsReceive[$countCodecs - 1];
                            }
                            $sdp = [
                                "v" => ["0"],
                                "o" => [$trunkController->ssrc . " 0 0 IN {$version} " . $localIp],
                                "s" => [cache::global()["interface"]["server"]["serverName"]],
                                "c" => ["IN {$version} " . $localIp],
                                "t" => ["0 0"],
                                "m" => ["audio " . $trunkController->audioReceivePort . " RTP/AVP {$originalSDP['codecMediaLine']}"],
                                "a" => [
                                    ...$originalSDP["codecRtpMap"],
                                    'ptime:20',
                                    'sendrecv',
                                ],
                            ];
                            $pv = sip::extractVia($headers["Via"][0]);
                            $modelRing = [
                                "method" => "183",
                                "methodForParser" => "SIP/2.0 183 Session Progress",
                                "headers" => [
                                    "Via" => $baseHeaders["Via"],
                                    "Max-Forwards" => ["70"],
                                    "From" => [sip::renderURI($uriFromParsed)],
                                    "To" => [sip::renderURI([
                                        "user" => sip::extractURI($originalData["headers"]["To"][0])["user"],
                                        "peer" => [
                                            "host" => $pv['received'] ?? network::getLocalIp(),
                                        ],
                                        "additional" => ["tag" => md5(random_bytes(10))],
                                    ])],
                                    "Call-ID" => $headers["Call-ID"],
                                    "CSeq" => $headers["CSeq"],
                                    "Contact" => [sip::renderURI([
                                        "user" => $uriTo["user"],
                                        "peer" => [
                                            "host" => $pv['received'] ?? network::getLocalIp(),
                                            "port" => $socket->port,
                                        ],
                                    ])],
                                ],
                                'sdp' => $sdp,
                            ];
                            $traces = cache::get("traces");
                            $traces[$callIdSession][] = [
                                "direction" => "send",
                                "sendTo" => trunkController::renderURI([
                                    "user" => $uriFrom["user"],
                                    "peer" => [
                                        "host" => $info["address"],
                                        "port" => $info["port"],
                                    ],
                                ]),
                                "data" => $modelRing,
                            ];
                            cache::define("traces", $traces);

                            $remoteAddressAudioDestination = explode(" ", $receive["sdp"]["c"][0])[2];
                            if (!$remoteAddressAudioDestination) {
                                $remoteAddressAudioDestination = $info["address"];
                            }
                            $remotePortAudioDestination = explode(" ", $receive["sdp"]["m"][0])[1];
                            $userDeclare = sip::extractURI($receive["headers"]["To"][0])["user"];
                            $codec = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                            $trunkController->declareVolume("{$remoteAddressAudioDestination}:{$remotePortAudioDestination}", $userDeclare, $codec[0]);
                            print cli::cl("yellow", "{$userDeclare} foi declarado como origem de volume em: {$remoteAddressAudioDestination}:{$remotePortAudioDestination}");
                            $trunkController->addMember($userDeclare);
                            $receiveCodecs = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                            $receiveCodecs = [$receiveCodecs[0]];
                            if (in_array("101", $receiveCodecs)) {
                                $receiveCodecs[] = ["101"];
                            }
                            $maxPtime = 150;
                            foreach ($receive["sdp"]["a"] as $a) {
                                if (str_contains($a, "maxptime")) {
                                    $maxPtime = explode(":", $a)[1];
                                    break;
                                }
                            }
                            $mapped = trunkController::getSDPModelCodecs($modelInvite['sdp']['a'] ?? $data['sdp']['a']);
                            if (empty($modelInvite['sdp'])) {
                                file_put_contents('observation_urgent.json', json_encode($receive, JSON_PRETTY_PRINT));
                            }
                            $codecMapper = [
                                $mapped['preferredCodec']['pt'] => strtoupper(implode('/', [
                                    $mapped['preferredCodec']['name'],
                                    $mapped['preferredCodec']['rate'],
                                ])),
                                $originalSDP['preferredCodec']['pt'] => strtoupper(implode('/', [
                                    $originalSDP['preferredCodec']['name'],
                                    $originalSDP['preferredCodec']['rate'],
                                ])),
                                $mapped['dtmfCodec']['pt'] => implode('/', [
                                    'telephone-event',
                                    $mapped['dtmfCodec']['rate'],
                                ]),
                                $originalSDP['dtmfCodec']['pt'] => implode('/', [
                                    'telephone-event',
                                    $originalSDP['dtmfCodec']['rate'],
                                ]),
                            ];
                            if (!$trunkController->isProxyMediaActive()) {
                                if ($details['account']['dmedia'] !== 'yes') {


                                    $trunkController->proxyMedia([
                                        "proxyIp" => $trunkController->localIp,
                                        "proxyPort" => $trunkController->audioReceivePort,
                                        "peerIp" => $remoteAddressAudioDestination,
                                        "peerPort" => $remotePortAudioDestination,
                                        "base" => sip::extractURI($data["headers"]["To"][0])["user"],
                                        "polo" => $uriTo["user"],
                                        "group" => $callId,
                                        "codecs" => $codecsModel,
                                        "codec" => $mapped['preferredCodec']['pt'],
                                        "codecMapper" => $codecMapper,
                                        "timeout" => $maxPtime,
                                        "ssrc" => $trunkController->ssrc,
                                        "rcc" => $rcc,
                                        "config" => $originalSDP['config'],
                                        "vad" => array_key_exists('vad', $details['account']) ? $details['account']['vad'] : false,
                                        "cid" => $trunkController->callId,
                                        "alternative" => "{$remoteAddressAudioDestination}:{$remotePortAudioDestination}",
                                    ]);
                                }
                            }
                            $mediaSetup = true;
                            if (!empty($details['account']['dmedia']) && $details['account']['dmedia'] === 'yes') {
                                $modelRing['sdp']['c'] = $receive["sdp"]["c"];
                                $modelRing['sdp']['o'] = $receive["sdp"]["o"];
                                $codecs = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                                $codecMain = null;
                                $codecEvent = null;
                                foreach ($receive["sdp"]["a"] as $a) {
                                    if (str_starts_with($a, 'rtpmap:')) {
                                        if (stripos($a, 'telephone-event/8000') !== false) {
                                            preg_match('/^rtpmap:(\d+)/i', $a, $m);
                                            $codecEvent = (int)($m[1] ?? null);
                                        } elseif ($codecMain === null) {
                                            preg_match('/^rtpmap:(\d+)/i', $a, $m);
                                            $codecMain = (int)($m[1] ?? null);
                                        }
                                    }
                                }
                                $codecMain = $codecMain ?? (int)($codecs[0] ?? 8);
                                $payloads = trim($codecMain . ($codecEvent ? " {$codecEvent}" : ""));
                                $modelRing['sdp']['m'] = ["audio {$remotePortAudioDestination} RTP/AVP {$payloads}"];
                                $avalues = [];
                                foreach ($receive["sdp"]["a"] as $a) {
                                    if (str_starts_with($a, "rtpmap:{$codecMain}") || $codecEvent && str_starts_with($a, "rtpmap:{$codecEvent}") || $codecEvent && str_starts_with($a, "fmtp:{$codecEvent}")) {
                                        $avalues[] = $a;
                                    }
                                }
                                foreach ($receive["sdp"]["a"] as $a) {
                                    if (stripos($a, "ptime:") === 0) {
                                        $avalues[] = $a;
                                        break;
                                    }
                                }
                                $avalues[] = "sendrecv";
                                $modelRing['sdp']['a'] = $avalues;
                            }
                            $socket->sendto($info["address"], $info["port"], sip::renderSolution($modelRing), $info["server_socket"]);
                            continue;
                        }
                    } else if (in_array($receive["method"], $trunkController->successCodes)) {
                        if ($details["account"]["pbc"]) {
                            if ($trunkController->progressLevel < 1) {
                                $trunkController->socket->close();
                                cache::persistExpungeCall($callIdSession);
                                continue 2;
                            } else {
                                break 2;
                            }
                        } else {
                            break 2;
                        }
                    } else if (in_array($receive["method"], $trunkController->failureCodes)) {
                        $retry++;
                        $trunkController->progressLevel = 0;
                        $trunkController->bye(false);
                        if ($retry >= $maxRetry) {
                            $trunkController->socket->close();
                            continue 2;
                        } else {
                            if (!$oldCallId) {
                                $oldCallId = $trunkController->callId;
                            }
                            $trunkController->callId = md5(random_bytes(10));
                            $modelInvite["headers"]["Call-ID"] = [$trunkController->callId];
                            $uriPmI = sip::extractURI($modelInvite["headers"]["From"][0]);
                            $modelInvite["headers"]["From"] = [sip::renderURI([
                                "user" => $uriPmI["user"],
                                "peer" => [
                                    "host" => $uriPmI["peer"]["host"],
                                    "port" => $uriPmI["peer"]["port"],
                                ],
                                "additional" => ["tag" => md5(random_bytes(10))],
                            ])];
                            $callId = $trunkController->callId;
                            $authSent = false;
                            if (str_contains($details["account"]["c"], "x")) {
                                for ($i = 0; $i < strlen($details["account"]["c"]); $i++) {
                                    if (strtolower($details["account"]["c"][$i]) == "x") {
                                        $details["account"]["c"][$i] = random_int(0, 9);
                                    }
                                }
                            }
                            if ($details["account"]["bi"] !== "active") {
                                $trunkController->setCallerId($details["account"]["c"]);
                            } else {
                                $trunkController->setCallerId(sip::loadBestCallerId($uriTo["user"]));
                            }
                            $traces = cache::get("traces");
                            $traces[$callIdSession][] = [
                                "direction" => "send",
                                "sendTo" => trunkController::renderURI([
                                    "user" => $uriTo["user"],
                                    "peer" => [
                                        "host" => $trunkController->host,
                                        "port" => $trunkController->port,
                                    ],
                                ]),
                                "data" => $modelInvite,
                            ];
                            cache::define("traces", $traces);
                            $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($modelInvite));
                            for ($nn = 5; $nn--;) {
                                $r = $trunkController->socket->recvfrom($peer, 1);
                                if ($r) {
                                    $receive = sip::parse($r);
                                    break;
                                }
                            }
                            if (!$r) {
                                $trunkController->bye();
                                $trunkController->callActive = false;
                                $trunkController->error = true;
                                $trunkController->receiveBye = true;
                                trunkController::resolveCloseCall($callId);
                                $traces = cache::get("traces");
                                $traces[$callIdSession][] = [
                                    "direction" => "send",
                                    "sendTo" => trunkController::renderURI([
                                        "user" => $uriFrom["user"],
                                        "peer" => [
                                            "host" => $info["address"],
                                            "port" => $info["port"],
                                        ],
                                    ]),
                                    "data" => sip::parse(renderMessages::e503Un($data["headers"])),
                                ];
                                cache::define("traces", $traces);
                                $socket->sendto($info["address"], $info["port"], renderMessages::e503Un($data["headers"], 'Sem resposta'), $info["server_socket"]);
                            } else {
                                $receive = sip::parse($r);
                                if (!array_key_exists("Call-ID", $receive["headers"])) {
                                    if (!array_key_exists("i", $receive["headers"])) {
                                        continue;
                                    }
                                    $receive["headers"]["Call-ID"] = $receive["headers"]["i"];
                                }
                            }
                            if (in_array($receive["method"], $trunkController->failureCodes)) {
                                $trunkController->callActive = false;
                                $trunkController->error = true;
                                $trunkController->receiveBye = true;
                                trunkController::resolveCloseCall($callId);
                                $errorModel = renderMessages::e503Un($data["headers"], 'Recebeu failure-code - ' . $receive["method"]);
                                $traces = cache::get("traces");
                                $traces[$callIdSession][] = [
                                    "direction" => "send",
                                    "sendTo" => trunkController::renderURI([
                                        "user" => $uriFrom["user"],
                                        "peer" => [
                                            "host" => $info["address"],
                                            "port" => $info["port"],
                                        ],
                                    ]),
                                    "data" => sip::parse($errorModel),
                                ];
                                cache::define("traces", $traces);
                                cache::persistExpungeCall($callIdSession);
                                $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                                $sop->unset($callId);
                                cache::unset("traces", $callIdSession);
                                $rpClient->rpcDelete($callId);
                                $rpClient->close();
                                return false;
                            } else {
                                for ($nn = 5; $nn--;) {
                                    $r = $trunkController->socket->recvfrom($peer, 1);
                                    if ($r) {
                                        $receive = sip::parse($r);
                                        if (in_array($receive["method"], $trunkController->successCodes)) {
                                            break;
                                        }
                                    }
                                }
                            }
                            $currentSecond = date("s");
                            $lastSecond = cache::get("lastSecond");
                            if ($lastSecond === null) {
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
                                cache::define("cpsMax", $cps);
                            }
                            $statsData = $socket->statsCalls->get('data');
                            $newMaxActive = max($statsData['maxActive'], $statsData['cts'] + 1);
                            $newCpsMax = max($statsData['cpsMax'], $cps);
                            $socket->statsCalls->set('data', [
                                'callsLastSecond' => $cps,
                                'totalCalls' => $statsData['totalCalls'] + 1,
                                'maxActive' => $newMaxActive,
                                'cpsMax' => $newCpsMax,
                                'cts' => $statsData['cts'] + 1,
                            ]);
                        }
                    }
                }
                if ($trunkController->receiveBye) {
                    $members = $trunkController->members;
                    foreach ($members as $member) {
                        $trunkController->removeMember($member);
                    }
                    $trunkController->receiveBye = true;
                    $trunkController->callActive = false;
                    $trunkController->error = true;
                    $trunkController->bye();
                    $traces = cache::get("traces");
                    $traces[$callIdSession][] = [
                        "direction" => "send",
                        "sendTo" => trunkController::renderURI([
                            "user" => $uriFrom["user"],
                            "peer" => [
                                "host" => $info["address"],
                                "port" => $info["port"],
                            ],
                        ]),
                        "data" => sip::parse(renderMessages::e503Un($data["headers"])),
                    ];
                    cache::define("traces", $traces);
                    $socket->sendto($info["address"], $info["port"], renderMessages::e503Un($data["headers"], 'nossos provedores demoraram responder!'), $info["server_socket"]);
                    $sop->unset($callId);
                    trunkController::resolveCloseCall($callId);
                    $rpClient->rpcDelete($callId);
                    $rpClient->close();
                    return false;
                }
            }
            $currentSecond = date("s");
            $lastSecond = cache::get("lastSecond");
            if ($lastSecond === null) {
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
                cache::define("cpsMax", $cps);
            }
            /** @var trunkController $trunkController */
            $trunkController = $sop->get($callId);
            $trunkController->callActive = true;
            if ($details["account"]["pbc"]) {
                if ($trunkController->progressLevel == 0) {
                    $trunkController->bye();
                    $trunkController->callActive = false;
                    $errorModel = renderMessages::e503Un($data["headers"], 'Sem progresso');
                    $trunkController->callActive = false;
                    $trunkController->error = true;
                    $trunkController->receiveBye = true;
                    trunkController::resolveCloseCall($callId);
                    $traces = cache::get("traces");
                    $traces[$callIdSession][] = [
                        "direction" => "send",
                        "sendTo" => trunkController::renderURI([
                            "user" => $uriFrom["user"],
                            "peer" => [
                                "host" => $info["address"],
                                "port" => $info["port"],
                            ],
                        ]),
                        "data" => sip::parse($errorModel),
                    ];
                    cache::define("traces", $traces);
                    $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                    $sop->unset($callId);
                    $rpClient->rpcDelete($callId);
                    $rpClient->close();
                    return false;
                }
            }
            if (empty($receive)) {
                $trunkController->callActive = false;
                $errorModel = renderMessages::e503Un($data["headers"], 'Sem resposta');
                cache::persistExpungeCall($callId);
                $traces = cache::get("traces");
                $traces[$callIdSession][] = [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                    ]),
                    "data" => sip::parse($errorModel),
                ];
                cache::define("traces", $traces);
                $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                $sop->unset($callId);
                $rpClient->rpcDelete($callId);
                $rpClient->close();
                return false;
            }
            if (!is_array($receive)) {
                $trunkController->callActive = false;
                $trunkController->error = true;
                $trunkController->receiveBye = true;
                trunkController::resolveCloseCall($callId);
                $errorModel = renderMessages::e503Un($data["headers"], 'Erro no recebimento');
                $traces = cache::get("traces");
                $traces[$callIdSession][] = [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                    ]),
                    "data" => sip::parse($errorModel),
                ];
                cache::define("traces", $traces);
                $rpClient->rpcDelete($callId);
                $rpClient->close();
                return $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
            }
            if (in_array($receive["method"], $trunkController->failureCodes)) {
                $trunkController->callActive = false;
                $trunkController->error = true;
                $trunkController->receiveBye = true;
                trunkController::resolveCloseCall($callId);
                $errorModel = renderMessages::e503Un($data["headers"], 'Falha no Invite final - cod ' . $receive["method"]);
                $traces = cache::get("traces");
                $traces[$callIdSession][] = [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                    ]),
                    "data" => sip::parse($errorModel),
                ];
                cache::define("traces", $traces);
                cache::persistExpungeCall($callId);
                $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                $sop->unset($callId);
                $rpClient->rpcDelete($callId);
                $rpClient->close();
                return false;
            }
            $typeStateCall = explode(' ', $receive['headers']['CSeq'][0])[1];
            if ($receive['method'] == '200') {
                if ($typeStateCall !== 'INVITE' or !array_key_exists('sdp', $receive)) {
                    $trunkController->callActive = false;
                    $trunkController->error = true;
                    $trunkController->receiveBye = true;
                    trunkController::resolveCloseCall($callId);
                    $errorModel = renderMessages::e503Un($data["headers"], "CSeq " . $typeStateCall . " inesperado");
                    $traces = cache::get("traces");
                    $traces[$callIdSession][] = [
                        "direction" => "send",
                        "sendTo" => trunkController::renderURI([
                            "user" => $uriFrom["user"],
                            "peer" => [
                                "host" => $info["address"],
                                "port" => $info["port"],
                            ],
                        ]),
                        "data" => sip::parse($errorModel),
                    ];
                    cache::define("traces", $traces);
                    cache::persistExpungeCall($callId);
                    $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                    $trunkController->socket->close();
                    $sop->unset($callId);
                    $rpClient->rpcDelete($callId);
                    $rpClient->close();
                    return false;
                }
            }
            $remoteAddressAudioDestination = explode(" ", $receive["sdp"]["c"][0])[2];
            $remotePortAudioDestination = explode(" ", $receive["sdp"]["m"][0])[1];
            if (!$remotePortAudioDestination or !$remoteAddressAudioDestination) {
                $trunkController->callActive = false;
                $errorModel = renderMessages::e503Un($data["headers"]);
                $traces = cache::get("traces");
                $traces[$callIdSession][] = [
                    "direction" => "send",
                    "sendTo" => trunkController::renderURI([
                        "user" => $uriFrom["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                    ]),
                    "data" => sip::parse($errorModel),
                ];
                $trunkController->bye();
                cache::define("traces", $traces);
                cache::persistExpungeCall($callId);
                $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                $trunkController->stopProxyMedia();
                $sop->unset($callId);
                InviteLockManager::unlockInvite($callId);
                $rpClient->rpcDelete($callId);
                $rpClient->close();
                return false;
            }
            $userDeclare = sip::extractURI($receive["headers"]["To"][0])["user"];
            $codec = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
            $trunkController->declareVolume("{$remoteAddressAudioDestination}:{$remotePortAudioDestination}", $userDeclare, $codec[0]);
            $trunkController->addMember($userDeclare);
            $trunkController->addMember($uriFrom["user"]);
            if (!$mediaSetup) {
                $mapped = trunkController::getSDPModelCodecs($receive['sdp']['a']);
                $codecMapper = [
                    $mapped['preferredCodec']['pt'] => strtoupper(implode('/', [
                        $mapped['preferredCodec']['name'],
                        $mapped['preferredCodec']['rate'],
                    ])),
                    $originalSDP['preferredCodec']['pt'] => strtoupper(implode('/', [
                        $originalSDP['preferredCodec']['name'],
                        $originalSDP['preferredCodec']['rate'],
                    ])),
                    $mapped['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $mapped['dtmfCodec']['rate'],
                    ]),
                    $originalSDP['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $originalSDP['dtmfCodec']['rate'],
                    ]),
                ];
            }
            if ($oldCallId) {
                $callId = $callIdSession;
            }
            $dialogProxy = cache::get('dialogProxy');
            $dialogProxy[$callIdSession][$uriFrom["user"]] = [
                "proxyPort" => $trunkController->audioReceivePort,
                "proxyIp" => $trunkController->localIp,
                "peerPort" => $mediaPort,
                "peerIp" => $mediaIp,
                "startBy" => true,
                "codec" => "{$pfc['name']} " . round($pfc['rate'] / 1000) . "kHz",
                "startedAt" => time(),
                "trunk" => $trunk["n"],
                "username" => $uriFrom["user"],
                "price" => $trunk["d"],
                "headers" => $modelInvite["headers"],
                "sdp" => $modelInvite["sdp"],
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
            $receiveCodecs = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
            $receiveCodecs = [$receiveCodecs[0]];
            if (in_array("101", $receiveCodecs)) {
                $receiveCodecs[] = ["101"];
            }
            $maxPtime = 150;
            foreach ($receive["sdp"]["a"] as $a) {
                if (str_contains($a, "maxptime")) {
                    $maxPtime = explode(":", $a)[1];
                    break;
                }
            }
            $modelCodecs["codecRtpMap"][] = "maxptime:{$maxPtime}";
            $dialogProxy = cache::get('dialogProxy');
            $parsedCodec = trunkController::getSDPModelCodecs($receive['sdp']['a']);
            $pfc = $parsedCodec['preferredCodec'];
            $dialogProxy[$callId][sip::extractURI($receive["headers"]["To"][0])["user"]] = [
                "proxyPort" => $trunkController->audioReceivePort,
                "proxyIp" => $trunkController->localIp,
                "peerPort" => $remotePortAudioDestination,
                "peerIp" => $remoteAddressAudioDestination,
                "startBy" => false,
               // 'refer' => true,
                "codec" => "{$pfc['name']} " . round($pfc['rate'] / 1000) . "kHz",
                "trunk" => $trunk["n"],
                "startedAt" => time(),
                "price" => $trunk["d"],
                "headers" => $receive["headers"],
                "sdp" => $receive["sdp"],
                "username" => sip::extractURI($receive["headers"]["To"][0])["user"],
            ];
            cache::define("dialogProxy", $dialogProxy);
            $codecsModel = [$codecs[0]];
            $codecsReceive = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
            $countCodecs = count($codecsReceive);
            if ($countCodecs > 1) {
                $codecsModel[] = $codecsReceive[$countCodecs - 1];
            }
            if (!$mediaSetup) {
                $trunkController->addMember($uriFrom["user"]);
                $trunkController->callActive = true;
                $mediaSetup = true;
                $trunkController->receiveBye = false;
                $allCodecsFinal = [];
                $originalInviteCodecs = explode(" ", value($data["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                $allCodecsFinal = array_merge($allCodecsFinal, $originalInviteCodecs);
                $receiveCodecsForMapper = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                $allCodecsFinal = array_merge($allCodecsFinal, $receiveCodecsForMapper);
                $allCodecsFinal = array_merge($allCodecsFinal, $codecsModel);
                $allCodecsFinal = array_merge($allCodecsFinal, $codecs);
                $staticCodecs = array_keys(trunkController::$supportedCodecStatic);
                $allCodecsFinal = array_merge($allCodecsFinal, $staticCodecs);
                if (array_key_exists('codecs', $trunk) && !empty($trunk['codecs'])) {
                    $trunkCodecsArray = explode(',', $trunk['codecs']);
                    $allCodecsFinal = array_merge($allCodecsFinal, $trunkCodecsArray);
                }
                if (isset($cdd) && is_array($cdd)) {
                    $allCodecsFinal = array_merge($allCodecsFinal, $cdd);
                }
                if (isset($codecsReceive)) {
                    $allCodecsFinal = array_merge($allCodecsFinal, $codecsReceive);
                }
                $sdpAttributes = $receive["sdp"]["a"] ?? [];
                foreach ($sdpAttributes as $attr) {
                    if (str_starts_with($attr, 'rtpmap:')) {
                        preg_match('/^rtpmap:(\d+)/', $attr, $matches);
                        if (isset($matches[1])) {
                            $allCodecsFinal[] = $matches[1];
                        }
                    }
                }
                if (isset($data["sdp"]["a"])) {
                    foreach ($data["sdp"]["a"] as $attr) {
                        if (str_starts_with($attr, 'rtpmap:')) {
                            preg_match('/^rtpmap:(\d+)/', $attr, $matches);
                            if (isset($matches[1])) {
                                $allCodecsFinal[] = $matches[1];
                            }
                        }
                    }
                }
                $allCodecsFinal = array_unique(array_filter($allCodecsFinal, function ($c) {
                    $cleaned = trim((string)$c);
                    return !empty($cleaned) && is_numeric($cleaned);
                }));
                sort($allCodecsFinal, SORT_NUMERIC);
                $mapped = trunkController::getSDPModelCodecs($receive['sdp']['a']);
                $codecMapper = [
                    $mapped['preferredCodec']['pt'] => strtoupper(implode('/', [
                        $mapped['preferredCodec']['name'],
                        $mapped['preferredCodec']['rate'],
                    ])),
                    $originalSDP['preferredCodec']['pt'] => strtoupper(implode('/', [
                        $originalSDP['preferredCodec']['name'],
                        $originalSDP['preferredCodec']['rate'],
                    ])),
                    $mapped['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $mapped['dtmfCodec']['rate'],
                    ]),
                    $originalSDP['dtmfCodec']['pt'] => implode('/', [
                        'telephone-event',
                        $originalSDP['dtmfCodec']['rate'],
                    ]),
                ];
            }
            $trunkController->headers200 = $receive;
            $ackModel = $trunkController->ackModel($receive["headers"]);
            $ifr = sip::extractURI($receive['headers']['Contact'][0])['peer'];
            $trunkController->socket->sendto($ifr['host'], (int)$ifr['port'], sip::renderSolution($ackModel));
            $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($ackModel));
            if (!empty($peer['address']) && !empty($peer['port'])) {
                $trunkController->socket->sendto($peer['address'], $peer['port'], sip::renderSolution($ackModel));
            }


            $uriMr = sip::extractURI($data["headers"]["From"][0]);
            if (empty($modelRing)) {
                $modelRing = [
                    "method" => "180",
                    "methodForParser" => "SIP/2.0 180 Ringing",
                    "headers" => [
                        "Via" => $headers["Via"],
                        "Max-Forwards" => ["70"],
                        "From" => $headers["From"],
                        "To" => [sip::renderURI([
                            "user" => $uriTo["user"],
                            "peer" => ["host" => network::getLocalIp()],
                            "additional" => ["tag" => md5(random_bytes(10))],
                        ])],
                        "Call-ID" => $headers["Call-ID"],
                        "CSeq" => $headers["CSeq"],
                        "Contact" => [sip::renderURI([
                            "user" => $uriTo["user"],
                            "peer" => [
                                "host" => network::getLocalIp(),
                                "port" => $socket->port,
                            ],
                        ])],
                    ],
                ];
            }
            $version = "IP4";
            if (str_contains($mediaIp, ":")) {
                $version = "IP6";
            }
            $localIp = cache::get('myIpAddress');
            if ($version == "IP6") {
                $localIp = ip2long($localIp);
            }
            $codecsModel = [$codecs[0]];
            $codecsReceive = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
            $countCodecs = count($codecsReceive);
            if ($countCodecs > 1) {
                $codecsModel[] = $codecsReceive[$countCodecs - 1];
            }
            if (in_array("101", $codecsReceive) && !in_array("101", $codecsModel)) {
                $codecsModel[] = "101";
            }
            foreach ($receive['sdp']['a'] as $row) {
                if (str_starts_with($row, "ssrc:")) {
                    $ssrce = explode(" ", $row);
                    $ssrc = null;
                    foreach ($ssrce as $key => $value) {
                        if (str_contains($value, 'ssrc:')) {
                            $ssrc = explode(":", $value)[1];
                            $trunkController->ssrc = $ssrc;
                        }
                    }
                    break;
                }
            }
            $modelCodecs = trunkController::codecsMapper($codecsModel);


            if ($details['account']['dmedia'] !== 'yes') {
                if (!$trunkController->isProxyMediaActive())
                    $trunkController->proxyMedia([
                        "proxyIp" => $trunkController->localIp,
                        "proxyPort" => $trunkController->audioReceivePort,
                        "peerIp" => $remoteAddressAudioDestination,
                        "peerPort" => $remotePortAudioDestination,
                        "base" => sip::extractURI($receive["headers"]["To"][0])["user"],
                        "polo" => $uriFrom["user"],
                        "group" => $callId,
                        "codecs" => $codecs,
                        "codec" => $codecs[0],
                        "codecMapper" => $codecMapper,
                        "config" => $originalSDP['config'],
                        "rcc" => $rcc,
                        "vad" => array_key_exists('vad', $details['account']) ? $details['account']['vad'] : false,
                        "cid" => $trunkController->callId,
                    ]);
            }


            $pv = sip::extractVia($receive['headers']["Via"][0]);
            $renderOK = [
                "method" => "200",
                "methodForParser" => "SIP/2.0 200 OK",
                "headers" => [
                    "Via" => $headers["Via"],
                    "Record-Route" => [
                        sip::renderURI([
                            'user' => $uriTo['user'],
                            'peer' => [
                                'host' => $trunkController->socket->getsockname()['address'],
                                'port' => $socket->port,
                                'extra' => 'lr',
                            ],
                            'additional' => ['tag' => $trunkController->callId],

                        ])
                    ],
                    "From" => [sip::renderURI([
                        "user" => sip::extractURI($data["headers"]["From"][0])["user"],
                        "peer" => [
                            "host" => $pv['received'],
                            "port" => $socket->port,
                        ],
                        "additional" => ["tag" => $uriMr["additional"]["tag"]],
                    ])],
                    "To" => $modelRing["headers"]["To"],
                    "Call-ID" => $data["headers"]["Call-ID"],
                    "CSeq" => $modelRing["headers"]["CSeq"],
                    "Allow" => ["INVITE, ACK, CANCEL, BYE, OPTIONS, REFER, NOTIFY"],
                    "Supported" => ["replaces, gruu"],
                    "Server" => [cache::global()["interface"]["server"]["serverName"]],
                    "Contact" => [sip::renderURI([
                        'user' => $uriTo['user'],
                        "peer" => [
                            'host' => $trunkController->socket->getsockname()['address'],
                            "port" => $socket->port,
                            "extra" => 'lr',
                        ],
                    ])],
                    "Max-Forwards" => ["70"],
                    "Content-Type" => ["application/sdp"],
                ],
                "sdp" => [
                    "v" => ["0"],
                    "o" => [$trunkController->ssrc . " 0 0 IN {$version} " . $localIp],
                    "s" => [cache::global()["interface"]["server"]["serverName"]],
                    "c" => ["IN {$version} " . $trunkController->socket->getsockname()['address']],
                    "t" => ["0 0"],
                    "m" => ["audio " . $trunkController->audioReceivePort . " RTP/AVP {$originalSDP['codecMediaLine']}"],
                    "a" => [
                        ...$originalSDP["codecRtpMap"],
                        'ptime:20',
                        'sendrecv',
                    ],
                ],
            ];
            $statsData = $socket->statsCalls->get('data');
            $socket->statsCalls->set('data', [
                'callsLastSecond' => $statsData['callsLastSecond'],
                'totalCalls' => $statsData['totalCalls'] + 1,
                'maxActive' => $statsData['maxActive'],
                'cpsMax' => $statsData['cpsMax'],
                'cts' => max(0, $statsData['cts'] - 1),
            ]);
            if (!empty($details['account']['dmedia']) && $details['account']['dmedia'] === 'yes') {
                $renderOK['sdp']['c'] = $receive["sdp"]["c"];
                $renderOK['sdp']['o'] = $receive["sdp"]["o"];
                $codecs = explode(" ", value($receive["sdp"]["m"][0], "RTP/AVP ", PHP_EOL));
                $codecMain = null;
                $codecEvent = null;
                foreach ($receive["sdp"]["a"] as $a) {
                    if (str_starts_with($a, 'rtpmap:')) {
                        if (stripos($a, 'telephone-event/8000') !== false) {
                            preg_match('/^rtpmap:(\d+)/i', $a, $m);
                            $codecEvent = (int)($m[1] ?? null);
                        } elseif ($codecMain === null) {
                            preg_match('/^rtpmap:(\d+)/i', $a, $m);
                            $codecMain = (int)($m[1] ?? null);
                        }
                    }
                }
                $codecMain = $codecMain ?? (int)($codecs[0] ?? 8);
                $payloads = trim($codecMain . ($codecEvent ? " {$codecEvent}" : ""));
                $renderOK['sdp']['m'] = ["audio {$remotePortAudioDestination} RTP/AVP {$payloads}"];
                $avalues = [];
                foreach ($receive["sdp"]["a"] as $a) {
                    if (str_starts_with($a, "rtpmap:{$codecMain}") || $codecEvent && str_starts_with($a, "rtpmap:{$codecEvent}") || $codecEvent && str_starts_with($a, "fmtp:{$codecEvent}")) {
                        $avalues[] = $a;
                    }
                }
                $avalues[] = "sendrecv";
                $renderOK['sdp']['a'] = $avalues;
            }
            $socket->sendto($info["address"], $info["port"], sip::renderSolution($renderOK), $info["server_socket"]);
            $trunkController->socket->sendto($ifr['host'], (int)$ifr['port'], sip::renderSolution($ackModel));
            $trunkController->socket->sendto($trunkController->host, $trunkController->port, sip::renderSolution($ackModel));


            cli::pcl("ACK SENDED TO " . $ifr['host'] . ":" . $ifr['port'], 'bold_blue');
            cli::pcl("ACK SENDED TO " . $trunkController->host . ":" . $trunkController->port, 'bold_blue');


            $uriContact = sip::extractURI($data["headers"]["Contact"][0]);
            $byeClient = [
                "method" => "BYE",
                "methodForParser" => "BYE sip:{$uriFrom["user"]}@{$uriContact["peer"]["host"]}:{$uriContact["peer"]["port"]} SIP/2.0",
                "headers" => [
                    "Via" => $headers["Via"],
                    "From" => $renderOK["headers"]["To"],
                    "To" => $renderOK["headers"]["From"],
                    "Max-Forwards" => ["70"],
                    "Call-ID" => $renderOK["headers"]["Call-ID"],
                    "CSeq" => [rand(21234, 73524) . " BYE"],
                    "Content-Length" => ["0"],
                    "Contact" => $renderOK["headers"]["Contact"],
                    "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE"],
                ],
            ];
            if (array_key_exists("Record-Route", $receive["headers"])) {
                $byeModelTrunk["headers"]["Route"] = $receive["headers"]["Record-Route"];
            }
            if (array_key_exists("Authorization", $data["headers"])) {
                $byeClient["headers"]["Authorization"] = $data["headers"]["Authorization"];
            }
            $trunkController->registerByeRecovery($byeClient, $info, $socket);
            $dialog = cache::get('dialogProxy');
            $arm = sip::extractURI($receive['headers']['Contact'][0]);
            $ub = $uriTo['user'];
            if (!empty($arm['user'])) {
                $ub = $arm['user'];
            }
            $byeModelTrunk = [
                "method" => "BYE",
                "methodForParser" => "BYE sip:{$ub}@{$arm['peer']['host']}:{$arm['peer']['port']} SIP/2.0",
                "headers" => [
                    "Via" => $receive["headers"]["Via"],
                    "Max-Forwards" => ["70"],
                    "From" => $receive["headers"]["From"],
                    "To" => $receive["headers"]["To"],
                    "Call-ID" => $receive["headers"]["Call-ID"],
                    "CSeq" => [$trunkController->csq . " BYE"],
                    "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
                ],
            ];
            $contact = sip::extractURI($data["headers"]["Contact"][0]);
            $byeContact = [
                "method" => "BYE",
                "methodForParser" => "BYE sip:{$contact["user"]}@{$contact["peer"]["host"]}:{$contact["peer"]["port"]} SIP/2.0",
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
            $socket->tpc->set($callId, ['data' => json_encode([
                'info' => $info,
                'refer' => false,
                'trunkData' => $trunk,
                'audioReceivePort' => $trunkController->audioReceivePort,
                'trunkSocketBind' => $trunkController->socketPortListen,
                'startedAt' => time(),
                'originalFrom' => $uriFrom["user"],
                'originalTo' => $uriTo["user"],
                'codecUsed' => $codecs[0],
                'data' => $modelInvite ?? $receive ?? $renderOK ?? '',
                'dialogProxy' => $dialogProxy[$callId],
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
                    sip::extractURI($data['headers']['To'][0])['user'] => [
                        'isUb' => true,
                        'model' => $byeModelTrunk,
                        'info' => $ifr,
                        'displayName' => $ub,
                        'isOriginator' => false,
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
                ],
            ], JSON_PRETTY_PRINT)]);
            $dialog[$callIdSession][$uriFrom["user"]]['recoverBye'] = [
                'info' => $ifr,
                'byeClient' => $byeModelTrunk,
            ];
            cache::define("dialogProxy", $dialog);
            if (array_key_exists("mt", $trunk)) {
                $maxTime = $trunk["mt"];
                $callerTimeInvoke = time() + $maxTime;
            } else {
                $maxTime = 300;
                $callerTimeInvoke = time() + 300;
            }
            $pricePerMinute = $trunk["d"];
            $pricePerMinuteTrunk = $trunk["d2"] ?? $trunk["d"];
            $lastTime = time();
            $hostTrunkSock = $trunkController->socket->getsockname()['address'] . ":" . $trunkController->socket->getsockname()['port'];
            $discountTick = 2;
            $sessionDebitKey = "session_debit_" . md5("{$callId}_{$uriUser}");
            $sessionStartTime = time();
            $totalSessionDebit = 0;
            if (!$socket->debitAtomic->exist($sessionDebitKey)) {
                $socket->debitAtomic->set($sessionDebitKey, [
                    'debit' => 0,
                    'started_at' => $sessionStartTime,
                    'call_id' => $callId,
                    'user' => $uriUser,
                    'trunk_id' => $trunkId,
                    'price_per_minute' => $pricePerMinute,
                ]);
            }


            for (; ;) {


                $rec = $trunkController->socket->recvfrom($peer, 1);
                if (!$sop->isset($callId)) {
                    break;
                }
                $dialogProxy = cache::get('dialogProxy');
                if (!is_array($dialogProxy) || !array_key_exists($callIdSession, $dialogProxy)) {
                    break;
                }
                if (!$socket->tpc->exist($callId)) {
                    break;
                }
                /** @var trunkController $trunkControllerCheck */
                $trunkControllerCheck = $sop->get($callId);
                if (!$trunkControllerCheck || !$trunkControllerCheck->callActive) {
                    break;
                }
                if (time() - $lastTime >= $discountTick) {
                    $currentTime = time();
                    $sessionElapsed = $currentTime - $sessionStartTime;
                    $debit = $pricePerMinute / 60 * $discountTick;
                    $debitTrunk = $pricePerMinuteTrunk / 60 * $discountTick;
                    $currentBalance = (int)sip::getTrunkByUserFromDatabase($uriUser)['account']['b'];
                    $currentSessionDebit = $socket->debitAtomic->exist($sessionDebitKey) ? $socket->debitAtomic->get($sessionDebitKey)['debit'] : 0;
                    if ($currentBalance - $currentSessionDebit - $debit <= 0) {
                        $trunkController->callActive = false;
                        $trunkController->receiveBye = true;
                        break;
                    }
                    if (!$socket->debitAtomic->exist($uriUser)) {
                        $socket->debitAtomic->set($uriUser, [
                            'debit' => 0,
                            'last_update' => $currentTime,
                        ]);
                    }
                    if (!$socket->debitAtomicTrunk->exist($trunkId)) {
                        $socket->debitAtomicTrunk->set($trunkId, [
                            'debit' => 0,
                            'last_update' => $currentTime,
                        ]);
                    }
                    if (!$sop->isset($callId) || !$trunkController->callActive) {
                        break;
                    }
                    if (!$socket->debitAtomic->exist($uriUser)) {
                        $socket->debitAtomic->set($uriUser, [
                            'debit' => $debit,
                            'last_update' => $currentTime,
                            'started_at' => $sessionStartTime,
                            'call_id' => $callId,
                            'user' => $uriUser,
                            'trunk_id' => $trunkId,
                            'price_per_minute' => $pricePerMinute,
                            'elapsed_seconds' => $sessionElapsed,
                        ]);
                    } else {
                        $socket->debitAtomic->incr($uriUser, 'debit', $debit);
                        $socket->debitAtomic->set($uriUser, [
                            'debit' => $socket->debitAtomic->get($uriUser)['debit'],
                            'last_update' => $currentTime,
                            'started_at' => $sessionStartTime,
                            'call_id' => $callId,
                            'user' => $uriUser,
                            'trunk_id' => $trunkId,
                            'price_per_minute' => $pricePerMinute,
                            'elapsed_seconds' => $sessionElapsed,
                        ]);
                    }
                    if (!$socket->debitAtomicTrunk->exist($trunkId)) {
                        $socket->debitAtomicTrunk->set($trunkId, [
                            'debit' => $debitTrunk,
                            'last_update' => $currentTime,
                            'active_calls' => 1,
                            'total_minutes' => $sessionElapsed / 60,
                        ]);
                    } else {
                        $socket->debitAtomicTrunk->incr($trunkId, 'debit', $debitTrunk);
                        $currentTrunkData = $socket->debitAtomicTrunk->get($trunkId);

                    }
                    $lastTime = $currentTime;
                }
                if (time() > $callerTimeInvoke) {
                    if (isset($socket->sessionControl)) {
                        $sessionControlKey = "session_" . md5("{$callId}_{$uriUser}");
                        $socket->sessionControl->del($sessionControlKey);
                    }
                    $statsData = $socket->statsCalls->get('data');
                    $socket->statsCalls->set('data', [
                        'callsLastSecond' => $statsData['callsLastSecond'],
                        'totalCalls' => $statsData['totalCalls'],
                        'maxActive' => $statsData['maxActive'],
                        'cpsMax' => $statsData['cpsMax'],
                        'cts' => max(0, $statsData['cts'] - 1),
                    ]);
                    $finalUserDebit = 0;
                    $finalTrunkDebit = 0;
                    $callDuration = time() - $sessionStartTime;
                    if ($socket->debitAtomic->exist($uriUser)) {
                        $userDebitData = $socket->debitAtomic->get($uriUser);
                        $finalUserDebit = $userDebitData['debit'] ?? 0;
                    }
                    if ($socket->debitAtomicTrunk->exist($trunkId)) {
                        $trunkDebitData = $socket->debitAtomicTrunk->get($trunkId);
                        $finalTrunkDebit = $trunkDebitData['debit'] ?? 0;
                    }
                    $calls = cache::get('dialogProxy');
                    if (callHandler::isInternalCall($callId)) {
                        callHandler::resolveCloseCall($callId, [
                            'bye' => true,
                            'recovery' => true,
                        ]);
                    } else {
                        trunkController::resolveCloseCall($callId, [
                            'bye' => true,
                            'recovery' => true,
                        ]);
                    }
                    if (is_array($calls) && array_key_exists($callId, $calls)) {
                        unset($calls[$callId]);
                    }
                    $trunkController->socket->close();
                    $sop->unset($callId);
                    break;
                }
                if ($trunkController->error) {
                    $trunkController->callActive = false;
                    $trunkController->receiveBye = true;
                    $finalDebit = 0;
                    if ($socket->debitAtomic->exist($uriUser)) {
                        $userDebitData = $socket->debitAtomic->get($uriUser);
                        $finalDebit = $userDebitData['debit'] ?? 0;
                    }
                    $statsData = $socket->statsCalls->get('data');
                    $socket->statsCalls->set('data', [
                        'callsLastSecond' => $statsData['callsLastSecond'],
                        'totalCalls' => $statsData['totalCalls'],
                        'maxActive' => $statsData['maxActive'],
                        'cpsMax' => $statsData['cpsMax'],
                        'cts' => max(0, $statsData['cts'] - 1),
                    ]);
                    trunkController::resolveCloseCall($data["headers"]["Call-ID"][0], [
                        "bye" => true,
                        "recovery" => true,
                    ]);
                    $errorModel = renderMessages::e503Un($data["headers"]);
                    cache::persistExpungeCall($callIdSession);
                    $socket->sendto($info["address"], $info["port"], $errorModel, $info["server_socket"]);
                    $sop->unset($callId);
                    break;
                }
                if (!$trunkController->callActive) {
                    if (isset($socket->sessionControl)) {
                        $sessionControlKey = "session_" . md5("{$callId}_{$uriUser}");
                        $socket->sessionControl->del($sessionControlKey);
                    }
                    break;
                }
                if ($trunkController->receiveBye) {
                    if ($socket->debitAtomic->exist($uriUser)) {
                        $userDebitData = $socket->debitAtomic->get($uriUser);
                        $finalDebit = $userDebitData['debit'] ?? 0;
                    }
                    break;
                }
                if (!$trunkController->callActive) {
                    break;
                }
                if ($trunkController->receiveBye) {
                    break;
                }
                if (!$rec) {
                    continue;
                }
                $receive = sip::parse($rec);
                if ($receive["method"] == "OPTIONS") {
                    $respond = renderMessages::respondOptions($receive["headers"]);
                    $trunkController->socket->sendto($peer["address"], $peer["port"], $respond);
                    continue;
                }
                if (in_array($receive["method"], [
                    "BYE",
                    "CANCEL",
                ])) {
                    $callRow = json_decode($socket->tpc->get($callId, 'data'), true);
                    foreach ($callRow['hangups'] as $uriHangUp => $hangup) {
                        $hangup['info']['port'] = intval($hangup['info']['port']);
                        $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($hangup['model']));
                    }
                    $socket->sendto($info['address'], $info['port'], sip::renderSolution($byeContact), $info["server_socket"]);
                    $model = renderMessages::respondOptions($receive["headers"]);
                    $receiveFromUri = sip::extractURI($receive["headers"]["From"][0]);
                    $trunkController->socket->sendto($trunkController->host, $trunkController->port, $model);
                    $trunkController->socket->sendto($info['address'], $info['port'], $model);
                    $trunkController->callActive = false;
                    $trunkController->receiveBye = true;
                    cache::persistExpungeCall($callIdSession);
                    trunkController::resolveCloseCall($data["headers"]["Call-ID"][0], [
                        "bye" => true,
                        "recovery" => true,
                    ]);
                    $socket->sendto($info["address"], $info["port"], sip::renderSolution($byeClient), $info["server_socket"]);
                    $dialogProxy = cache::get("dialogProxy");
                    if (array_key_exists($callIdSession, $dialogProxy)) {
                        unset($dialogProxy[$callIdSession]);
                    }
                    cache::define("dialogProxy", $dialogProxy);
                    $trunkController->__destruct();
                    $sop->unset($callId);
                    break;
                }
            }
            $statsData = $socket->statsCalls->get('data');
            $socket->statsCalls->set('data', [
                'callsLastSecond' => $statsData['callsLastSecond'],
                'totalCalls' => $statsData['totalCalls'],
                'maxActive' => $statsData['maxActive'],
                'cpsMax' => $statsData['cpsMax'],
                'cts' => max(0, $statsData['cts'] - 1),
            ]);
            $finalUserDebit = 0;
            $finalTrunkDebit = 0;
            $callDuration = time() - $sessionStartTime;
            if ($socket->debitAtomic->exist($uriUser)) {
                $userDebitData = $socket->debitAtomic->get($uriUser);
                $finalUserDebit = $userDebitData['debit'] ?? 0;
            }
            if ($socket->debitAtomicTrunk->exist($trunkId)) {
                $trunkDebitData = $socket->debitAtomicTrunk->get($trunkId);
                $finalTrunkDebit = $trunkDebitData['debit'] ?? 0;
            }
            if (isset($socket->debitAudit)) {
                $auditKey = "audit_" . md5($callId . "_" . time());
                $socket->debitAudit->set($auditKey, [
                    'call_id' => $callId,
                    'user' => $uriUser,
                    'trunk_id' => $trunkId,
                    'start_time' => $sessionStartTime,
                    'end_time' => time(),
                    'duration_seconds' => $callDuration,
                    'total_debit' => $finalUserDebit,
                    'price_per_minute' => $pricePerMinute,
                    'status' => 'COMPLETED',
                    'reason' => $trunkController->receiveBye ? 'BYE_RECEIVED' : 'NORMAL_END',
                ]);
            }
            if ($trunkController->receiveBye) {
                $renderBye = sip::renderSolution($byeModelTrunk);
                $trunkController->socket->sendto($ifr['host'], $ifr['port'], $renderBye);
            }
            cache::persistExpungeCall($callIdSession);
            trunkController::resolveCloseCall($data["headers"]["Call-ID"][0], [
                "bye" => true,
                "recovery" => true,
            ]);
            $sop->unset($callId);
            InviteLockManager::unlockInvite($callId);
            $trunkController->stopProxyMedia();
            try {
                if (isset($rpClient) && $rpClient) {
                    $rpClient->rpcDelete($callId);
                    $rpClient->close();
                } else {
                    $rpClient = new rpcClient();
                    $rpClient->rpcDelete($callId);
                    $rpClient->close();
                }
            } catch (Exception $e) {
                error_log("RPC Cleanup Error - CallID: {$callId} - " . $e->getMessage());
            }
            return true;
        } else {
        }
        $sop->unset($callId);
        InviteLockManager::unlockInvite($callId);
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