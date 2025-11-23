<?php

namespace plugins\Start;

use ArrayObjectDynamic;
use Exception;
use ObjectProxy;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Extension\plugins;
use plugins\Utils\cache;
use sip;
use Swoole\Coroutine;
use Swoole\Timer;
use trunkController;

class events
{


    public static function main(\ServerSocket $server): void
    {
        cli::show();
        cache::define('queueMessages', []);
        Timer::tick(1000, function () use ($server) {
            $cfg = 'queueMessages.json';
            if (!file_exists($cfg)) {
                Coroutine::writeFile($cfg, json_encode([], JSON_PRETTY_PRINT));
            }
            $queueMessages = json_decode(file_get_contents($cfg), true);
            if (!is_array($queueMessages)) {
                $queueMessages = [];
            }
            foreach ($queueMessages as $messages) {
                cache::join('queueMessages', $messages);
            }
            Coroutine::writeFile($cfg, json_encode([], JSON_PRETTY_PRINT));
        });
        Timer::tick(1000, function () use ($server) {
            $onlineUsers = cache::getConnections();
            $queueMessages = cache::get('queueMessages');
            if (!empty($queueMessages)) {
                $messagesToUpdate = [];
                foreach ($queueMessages as $index => $messageData) {
                    if (!isset($messageData['message']) || !isset($messageData['users'])) {
                        continue;
                    }
                    $message = $messageData['message'];
                    $targetUsers = $messageData['users'];
                    $remainingUsers = [];
                    foreach ($targetUsers as $username) {
                        if (isset($onlineUsers[$username])) {
                            $info = $onlineUsers[$username];
                            $smsVoipModel = [
                                "method" => "MESSAGE",
                                "methodForParser" => "MESSAGE sip:{$username}@{$info['address']}:{$info['port']} SIP/2.0",
                                "headers" => [
                                    "Via" => ["SIP/2.0/UDP " . $server->host . ":" . $server->port . ";branch=z9hG4bK" . uniqid()],
                                    "Max-Forwards" => ["70"],
                                    "To" => [sip::renderURI([
                                        "user" => $username,
                                        "peer" => [
                                            "host" => $info["address"],
                                            "port" => $info["port"],
                                        ],
                                    ])],
                                    "From" => [sip::renderURI([
                                        "user" => "avisos",
                                        "peer" => [
                                            "host" => $server->host,
                                            "port" => $server->port,
                                        ],
                                        "additional" => ["tag" => uniqid(time())],
                                    ])],
                                    "Call-ID" => [md5(time() . $username)],
                                    "CSeq" => ["1 MESSAGE"],
                                    "User-Agent" => [cache::global()['interface']['server']['serverName'] ?? "SpechShop-SIP"],
                                    "Content-Type" => ["text/plain"],
                                    "Content-Length" => [strlen($message)],
                                ],
                                "body" => $message,
                            ];
                            $sipMessage = \sip::renderSolution($smsVoipModel);
                            $server->sendto($info['address'], $info['port'], $sipMessage);
                            cli::pcl("Mensagem enviada para {$username} ({$info['address']}:{$info['port']})", 'green');
                        } else {
                            $remainingUsers[] = $username;
                        }
                    }
                    if (!empty($remainingUsers)) {
                        $messagesToUpdate[$index] = [
                            'message' => $message,
                            'users' => $remainingUsers,
                        ];
                    } else {
                        cli::pcl("Mensagem entregue para todos os usuários, removendo da fila", 'cyan');
                    }
                }
                cache::define('queueMessages', array_values($messagesToUpdate));
            }
        });
        cache::define('tracesBuffer', []);
        cache::define('tracesLastHash', '');
        cache::define('catp', 0);
        Timer::tick(3000, function () {
            try {
                /** @var ArrayObjectDynamic $objects */
                $objects = $GLOBALS['object'];
            } catch (Exception $e) {
                \Plugin\Utils\cli::pcl("Erro ao acessar o objeto global 'object': " . $e->getMessage(), 'red');
                \Plugin\Utils\cli::pcl("Certifique-se de que o objeto 'object' está definido corretamente.", 'yellow');
                return;
            }
            $object = $objects;
            $ac = 0;
            foreach ($object as $idCall => $calls) {
                $logDebug = function (string $msg, string $color = 'white') use ($idCall) {
                    $time = date('Y-m-d H:i:s');
                    \Plugin\Utils\cli::pcl("[{$time}] [ID: {$idCall}] {$msg}", $color);
                };
                $errCode = $calls->socket->errCode ?? null;
                if ($errCode === 110 && $calls->receiveBye === true) {
                    $logDebug("⚠️ Socket com erro 110 e `receiveBye` = true", 'yellow');
                    $calls->socket->close();
                    $calls->__destruct();
                    unset($object->{$idCall});
                    $logDebug("✅ Chamada {$idCall} destruída com sucesso", 'bold_green');
                } else {
                    /** @var ObjectProxy $sop */
                    $sop = cache::get('swooleObjectProxy');
                    if (!$sop->isset($idCall)) {
                        $logDebug("⚠️ Call {$idCall} não existe mais", 'yellow');
                        $calls->socket->close();
                        $calls->__destruct();
                        unset($object->{$idCall});
                    } else if ($calls->receiveBye === true) {
                        $logDebug("✅ Call {$idCall} ainda está ativa", 'green');
                        $logDebug("ReceiveBye: " . ($calls->receiveBye ? 'true' : 'false'), 'cyan');
                        $logDebug("⚠️ Socket com erro 110 e `receiveBye` = true", 'yellow');
                        $calls->socket->close();
                        $calls->__destruct();
                        unset($object->{$idCall});
                        $logDebug("✅ Chamada {$idCall} destruída com sucesso usando ultimo recurso.", 'bold_green');
                    }
                }
                $ac++;
                unset($logDebug);
            }
            if ($ac !== cache::get('catp')) {
                \Plugin\Utils\cli::pcl("Total de chamadas processadas: {$ac}", 'green');
                cache::define('catp', $ac);
            }
        });
        Timer::tick(2000, function () use ($server) {


            $callsJsonData = [];
            $tpc = $server->tpc;
            $currentCalls = [];
            foreach ($tpc as $callId => $row) {
                $currentCalls[] = $callId;
                $tpcCallData = json_decode($row['data'], true);
                if (!is_array($tpcCallData) || empty($tpcCallData)) {
                    continue;
                }
                $trunkData = $tpcCallData['trunkData'] ?? [];
                $callInfo = $tpcCallData['info'] ?? [];
                $callStartTime = $tpcCallData['startedAt'] ?? time();
                $callsJsonData[$callId] = [];
                $codecUsed = "8";
                if (isset($tpcCallData['data']['sdp']['m'][0])) {
                    $mediaLine = $tpcCallData['data']['sdp']['m'][0];
                    if (preg_match('/RTP\/AVP\s+(\d+)/', $mediaLine, $matches)) {
                        $codecUsed = $matches[1];
                    }
                }
                if (isset($tpcCallData['hangups']) && is_array($tpcCallData['hangups'])) {
                    foreach ($tpcCallData['hangups'] as $username => $hangupData) {
                        if (!is_array($hangupData) || !isset($hangupData['model'])) {
                            continue;
                        }
                        $model = $hangupData['model'];
                        $info = $hangupData['info'] ?? [];
                        $fromHeader = $model['headers']['From'][0] ?? '';
                        $toHeader = $model['headers']['To'][0] ?? '';
                        $isStartBy = false;
                        $displayName = $username;
                        if (isset($tpcCallData['data']['headers']['From'][0])) {
                            $originalFrom = $tpcCallData['data']['headers']['From'][0];
                            if (str_contains($fromHeader, $username) || str_contains($originalFrom, $username)) {
                                $isStartBy = true;
                            }
                        }
                        if (preg_match('/sip:([^@]+)@/', $fromHeader, $matches)) {
                            $displayName = $matches[1];
                        } elseif (preg_match('/sip:([^@]+)@/', $toHeader, $matches)) {
                            $displayName = $matches[1];
                        }
                        if (!$isStartBy && isset($tpcCallData['data']['headers']['To'][0])) {
                            $originalTo = $tpcCallData['data']['headers']['To'][0];
                            if (preg_match('/sip:([^@]+)@/', $originalTo, $matches)) {
                                $displayName = $matches[1];
                            }
                        }


                        $userCallData = $tpcCallData['dialogProxy'];
                        if ($isStartBy && !empty($callInfo)) {
                            $cancelRender = "CANCEL sip:{$displayName}@{$info['host']}:{$info['port']} SIP/2.0\r\n";
                            $cancelRender .= "Via: SIP/2.0/UDP " . network::getLocalIp() . ":" . ($server->port ?? 5060) . ";branch=z9hG4bK" . uniqid() . "\r\n";
                            $cancelRender .= "From: <sip:" . network::getLocalIp() . ">\r\n";
                            $cancelRender .= "To: <sip:{$displayName}@{$info['host']}:{$info['port']}>\r\n";
                            $cancelRender .= "Call-ID: {$callId}\r\n";
                            $cancelRender .= "CSeq: 1 CANCEL\r\n";
                            $cancelRender .= "Max-Forwards: 70\r\n";
                            $cancelRender .= "Content-Length: 0\r\n\r\n";
                            $userCallData["preCancel"] = [
                                "address" => $info['host'] ?? $callInfo['address'] ?? "",
                                "port" => $info['port'] ?? $callInfo['port'] ?? 0,
                                "render" => $cancelRender,
                            ];
                        }
                        $userCallData["recoverBye"] = [
                            "info" => [
                                "host" => $info['host'] ?? "",
                                "port" => strval($info['port'] ?? ""),
                                "extra" => "",
                            ],
                            "byeClient" => [
                                "method" => "BYE",
                                "methodForParser" => $model['methodForParser'] ?? "BYE sip:{$displayName}@{$info['host']}:{$info['port']} SIP/2.0",
                                "headers" => $model['headers'] ?? [],
                            ],
                        ];
                        $callsJsonData[$callId] = $tpcCallData['dialogProxy'];
                    }
                }
                if (empty($callsJsonData[$callId]) && isset($tpcCallData['data']['headers'])) {
                    $headers = $tpcCallData['data']['headers'];
                    if (isset($headers['From'][0])) {
                        if (preg_match('/sip:([^@]+)@/', $headers['From'][0], $matches)) {
                            $originUser = $matches[1];
                            $callsJsonData[$callId][$originUser] = [
                                "proxyPort" => $tpcCallData['audioReceivePort'] ?? 0,
                                "proxyIp" => network::getLocalIp(),
                                "peerPort" => strval($callInfo['port'] ?? "0"),
                                "peerIp" => $callInfo['address'] ?? "0.0.0.0",
                                "startBy" => true,
                                'refer' => 'yes',
                                "codec" => $codecUsed,
                                "startedAt" => $callStartTime,
                                "trunk" => $trunkData['n'] ?? "UNKNOWN",
                                "username" => $originUser,
                                "price" => $trunkData['d'] ?? "0.00",
                                "headers" => $headers,
                            ];
                            if ($server->tpc->exist($callId)) {
                                $tpcCallData = json_decode($server->tpc->get($callId, 'data'), true);
                                if (!empty($tpcCallData['refer'])) {
                                    if ($tpcCallData['refer'] === 'yes') {
                                        $callsJsonData[$callId][$originUser]['refer'] = 'yes';
                                    }
                                }
                            }
                        }
                    }
                    if (isset($headers['To'][0])) {
                        if (preg_match('/sip:([^@]+)@/', $headers['To'][0], $matches)) {
                            $destUser = $matches[1];
                            $callsJsonData[$callId][$destUser] = [
                                "proxyPort" => $tpcCallData['audioReceivePort'] ?? 0,
                                "proxyIp" => network::getLocalIp(),
                                "peerPort" => "0",
                                "peerIp" => "0.0.0.0",
                                "startBy" => false,
                                'refer' => true,
                                "codec" => $codecUsed,
                                "startedAt" => $callStartTime,
                                "trunk" => $trunkData['n'] ?? "UNKNOWN",
                                "username" => $destUser,
                                "price" => $trunkData['d'] ?? "0.00",
                                "headers" => $headers,

                            ];
                            if ($server->tpc->exist($callId)) {
                                $tpcCallData = json_decode($server->tpc->get($callId, 'data'), true);
                                if (!empty($tpcCallData['refer'])) {
                                    if ($tpcCallData['refer'] === 'yes') {
                                        $callsJsonData[$callId][$destUser]['refer'] = 'yes';
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $currentCallsJson = [];
            if (file_exists('calls.json')) {
                $currentCallsJson = json_decode(file_get_contents('calls.json'), true) ?? [];
            }
            $needsUpdate = false;
            foreach (array_keys($currentCallsJson) as $callId) {
                if (!in_array($callId, $currentCalls)) {
                    $needsUpdate = true;
                    break;
                }
            }
            if (!$needsUpdate && count($callsJsonData) !== count($currentCallsJson)) {
                $needsUpdate = true;
            }
            if ($needsUpdate) {


                Coroutine::writeFile('calls.json', json_encode($callsJsonData, JSON_PRETTY_PRINT));
                cache::define('dialogProxy', $callsJsonData);
                $activeCount = count($callsJsonData);
                $removedCount = count($currentCallsJson) - $activeCount;
                cli::pcl("TPC->JSON atualizado: {$activeCount} ativas" . ($removedCount > 0 ? ", {$removedCount} removidas" : ""), 'cyan');
            }
        });


        Timer::tick(1000, function () use ($server) {
            $filePath = 'calls.json';
            if (!file_exists($filePath)) {
                Coroutine::writeFile($filePath, json_encode([], JSON_PRETTY_PRINT));
                return;
            }
            $fileContent = file_get_contents($filePath);
            $calls = json_decode($fileContent, true);
            if (!is_array($calls)) {
                $calls = cache::get('dialogProxy') ?? [];
                if (!is_array($calls)) {
                    $calls = [];
                }
                Coroutine::writeFile($filePath, json_encode($calls, JSON_PRETTY_PRINT));
                return;
            }
            if (str_contains($fileContent, 'terminate')) {
                $callsModified = false;
                foreach ($calls as $idCall => $members) {
                    if (empty($members)) {
                        unset($calls[$idCall]);
                        $callsModified = true;
                        continue;
                    }
                    if (!is_array($members)) {
                        continue;
                    }
                    foreach ($members as $user => $dataCall) {
                        if (is_array($dataCall) && array_key_exists('terminate', $dataCall)) {
                            cli::pcl("Detectado terminate para chamada {$idCall}", 'red');
                            if (isset($server->tpc) && $server->tpc->exist($idCall)) {
                                $tpcData = $server->tpc->get($idCall, 'data');
                                if ($tpcData) {
                                    $tpcDataDecoded = json_decode($tpcData, true);
                                    if (is_array($tpcDataDecoded)) {
                                        $hangups = $tpcDataDecoded['hangups'] ?? [];
                                        if (empty($hangups) || !is_array($hangups)) {
                                            cli::pcl("⚠️ Hangups vazio para chamada {$idCall}, deletando row da TPC", 'yellow');
                                            $server->tpc->del($idCall);
                                            if (array_key_exists($idCall, $calls)) {
                                                unset($calls[$idCall]);
                                                $callsModified = true;
                                            }
                                            cli::pcl("✅ Chamada {$idCall} removida (sem participantes)", 'green');
                                            continue;
                                        }
                                        cli::pcl("Enviando BYE para " . count($hangups) . " participante(s) da chamada {$idCall}", 'yellow');
                                        foreach ($hangups as $member => $hangup) {
                                            if (!is_array($hangup)) {
                                                continue;
                                            }
                                            $model = $hangup['model'] ?? [];
                                            $info = $hangup['info'] ?? [];
                                            if (!empty($model) && !empty($info)) {
                                                $host = $info['host'] ?? null;
                                                $port = $info['port'] ?? null;
                                                if ($host && $port) {
                                                    $byeMessage = sip::renderSolution($model);
                                                    $server->sendto($host, (int)$port, $byeMessage);
                                                    cli::pcl("BYE enviado para {$member} em {$host}:{$port}", 'green');
                                                } else {
                                                    cli::pcl("Informações de host/port inválidas para {$member}", 'red');
                                                }
                                            } else {
                                                cli::pcl("Dados incompletos para enviar BYE ao participante {$member}", 'red');
                                            }
                                        }
                                    }
                                }
                                $server->tpc->del($idCall);
                                cli::pcl("Chamada {$idCall} removida da TPC", 'cyan');
                            } else {
                                cli::pcl("⚠️ Chamada {$idCall} não encontrada na TPC", 'yellow');
                            }
                            if (\callHandler::isInternalCall($idCall)) {
                                \callHandler::resolveCloseCall($idCall, [
                                    'bye' => true,
                                    'recovery' => true,
                                ]);
                            } else {
                                trunkController::resolveCloseCall($idCall, [
                                    'bye' => true,
                                    'recovery' => true,
                                ], json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10), JSON_PRETTY_PRINT));
                            }
                            if (array_key_exists($idCall, $calls)) {
                                unset($calls[$idCall]);
                                $callsModified = true;
                            }
                            cli::pcl("Chamada {$idCall} finalizada completamente", 'bold_green');
                        }
                    }
                }
                if ($callsModified) {
                    cache::define('dialogProxy', $calls);
                    Coroutine::writeFile($filePath, json_encode($calls, JSON_PRETTY_PRINT));
                }
            }
        });
        $menuCallback = [
            'a' => function () {
                $dialogProxy = cache::global()['dialogProxy'] ?? [];
                $formattedCalls = '';
                if (!is_array($dialogProxy)) {
                    $dialogProxy = [];
                }
                foreach ($dialogProxy as $callId => $users) {
                    $formattedCalls .= "Call: {$callId} -> " . PHP_EOL;
                    if (!is_array($users)) {
                        $users = [];
                    }
                    foreach ($users as $user => $data) {
                        $formattedCalls .= cli::color('bold_green', "Proxy: {$data['proxyIp']}:{$data['proxyPort']}");
                        $formattedCalls .= " [Iniciado há " . cli::color('bold_yellow', time() - $data['startedAt']) . "s]";
                        $totalActive = time() - $data['startedAt'];
                        if ($totalActive > 30) {
                            $channels = count($users);
                            $formattedCalls .= " " . cli::color('bold_red', "Call zumbi detectada! {$channels} canais ativos");
                            cache::persistExpungeCall($callId);
                        } else {
                            $formattedCalls .= " " . (in_array($data['proxyPort'], cache::global()['portsUse']) ? cli::color('bold_green', 'Ativo') : cli::color('bold_red', 'Inativo'));
                        }
                        $formattedCalls .= " -> " . cli::color('bold_yellow', "{$user} recebe som em {$data['peerIp']}:{$data['peerPort']}");
                        $formattedCalls .= PHP_EOL;
                    }
                    $formattedCalls .= PHP_EOL;
                }
                print $formattedCalls;
            },
            'l' => function () {
                $connections = cache::getConnections();
                $formattedConnections = '';
                foreach ($connections as $user => $connection) {
                    $formattedConnections .= cli::color('green', $user) . ' -> ' . cli::color('yellow', $connection['address'] . ':' . $connection['port']) . " - " . (!empty($connection['userAgent']) ? cli::color('cyan', $connection['userAgent']) : '') . PHP_EOL;
                }
                print_r($formattedConnections);
            },
            't' => function () {
                $trunks = cache::global()['trunks'];
                $database = cache::global()['database'];
                $formattedTrunks = '';
                foreach ($trunks as $idTrunk => $trunk) {
                    $usersVinculated = 0;
                    foreach ($database as $account) {
                        if ($account['t'] == $idTrunk) {
                            $usersVinculated++;
                        }
                    }
                    $formattedTrunks .= cli::color('green', 'ID: ') . cli::color('yellow', $idTrunk) . ' | ' . cli::color('green', 'Usuário: ') . cli::color('yellow', $trunk['u']) . ' | ' . cli::color('green', 'Senha: ') . cli::color('yellow', $trunk['p']) . ' | ' . cli::color('green', 'Host: ') . cli::color('yellow', $trunk['h']) . ' | ' . cli::color('green', 'Extensões vinculadas: ') . cli::color('yellow', $usersVinculated) . PHP_EOL;
                }
                print_r($formattedTrunks);
            },
            'c' => function () {
                $database = cache::global()['database'];
                $formattedDatabase = '';
                foreach ($database as $account) {
                    $formattedDatabase .= 'Saldo: ' . cli::color('yellow', "R\$ " . number_format($account['b'], 2, ',', '.')) . " | " . cli::color('green', 'Usuário: ') . cli::color('yellow', $account['u']) . ' | ' . cli::color('green', 'Senha: ') . cli::color('yellow', $account['p']) . ' | ' . cli::color('green', 'CallerID: ') . cli::color('yellow', $account['c']) . PHP_EOL;
                }
                print_r($formattedDatabase);
            },
            'b' => function () {
                $bannedIps = cache::global()['bannedIps'];
                $ip = readline("Digite o IP a ser banido: ");
                if (isset($bannedIps[$ip])) {
                    print cli::color('red', "O IP {$ip} já está banido!") . PHP_EOL;
                    return;
                }
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    print cli::color('red', "IP inválido!") . PHP_EOL;
                    return;
                }
                $bannedIps[$ip] = [
                    'expires' => time() + 120,
                    'sip' => 'unknown',
                ];
                Coroutine::writeFile('banned.json', json_encode($bannedIps));
                cache::define('bannedIps', $bannedIps);
            },
            'd' => function () {
                $bannedIps = cache::global()['bannedIps'];
                $ip = readline("Digite o IP a ser desbanido: ");
                unset($bannedIps[$ip]);
                Coroutine::writeFile('banned.json', json_encode($bannedIps));
                cache::define('bannedIps', $bannedIps);
            },
            'i' => function () {
                $bannedIps = cache::global()['bannedIps'];
                $formattedBannedIps = '';
                foreach ($bannedIps as $ip => $data) {
                    if ($data['expires'] - time() < 1) {
                        unset($bannedIps[$ip]);
                        cache::define('bannedIps', $bannedIps);
                        file_put_contents('banned.json', json_encode($bannedIps));
                        continue;
                    }
                    $formattedBannedIps .= cli::color('red', $ip) . ' - ' . cli::color('yellow', $data['expires'] - time()) . 's - ' . cli::color('cyan', $data['sip']) . PHP_EOL;
                }
                print_r($formattedBannedIps);
            },
            'q' => function () {
                Timer::clearAll();
                cache::global()['serverSocket']->shutdown();
                return 'break';
            },
            'p' => function () {
                $configInterfaceManageFile = plugins::baseDir() . 'manage/plugins/configInterface.json';
                $webSettings = json_decode(file_get_contents($configInterfaceManageFile), true);
                print cli::color('green', "Porta atual: ") . cli::color('yellow', $webSettings['port']) . PHP_EOL;
                print cli::color('green', "Digite a nova porta: ");
                $newPort = readline();
                if (!is_numeric($newPort)) {
                    print cli::color('red', "Porta inválida!") . PHP_EOL;
                    return;
                }
                $webSettings['port'] = $newPort;
                Coroutine::writeFile($configInterfaceManageFile, json_encode($webSettings, JSON_PRETTY_PRINT));
                print cli::color('green', "Porta alterada com sucesso!") . PHP_EOL;
            },
            'r' => function () {
                $configInterfaceManageFile = plugins::baseDir() . 'manage/plugins/configInterface.json';
                $webSettings = json_decode(file_get_contents($configInterfaceManageFile), true);
                $port = $webSettings['port'];
                shell_exec('killport ' . $port);
                print cli::color('green', "Servidor reiniciado com sucesso!") . PHP_EOL;
            },
            'x' => function () {
                $r = readline('Permitir debugs na tela? [y/n] ');
                if (strtolower($r) == 'y') {
                    cache::define('allowDebugs', true);
                } else {
                    cache::define('allowDebugs', false);
                }
            },
            'e' => function () use ($server) {
                $fileEval = 'eval.php';
                if (!file_exists($fileEval)) {
                    Coroutine::writeFile($fileEval, 'var_dump("Hello World");');
                }
                $code = file_get_contents($fileEval);
                if (str_contains($code, '<?php')) {
                    $code = str_replace('<?php', '', $code);
                }
                try {
                    eval($code);
                } catch (\Throwable $e) {
                    cli::pcl($e->getMessage(), 'red');
                    cli::pcl($e->getFile() . ':' . $e->getLine(), 'red');
                    cli::pcl($e->getTraceAsString(), 'red');
                }
                try {
                    /** @var ArrayObjectDynamic $objects */
                    $objects = $GLOBALS['object'];
                } catch (Exception $e) {
                    \Plugin\Utils\cli::pcl("Erro ao acessar o objeto global 'object': " . $e->getMessage(), 'red');
                    \Plugin\Utils\cli::pcl("Certifique-se de que o objeto 'object' está definido corretamente.", 'yellow');
                    return;
                }
                $object = $objects;
                $ac = 0;
                foreach ($object as $key => $value) {
                    $ac++;
                }
                cli::pcl("Foram encontrados {$ac} objetos no objeto global 'object'.", 'green');
                $tpc = $server->tpc;
                foreach ($tpc as $key => $row) {
                }
                cli::pcl("Foram encontrados {$tpc->count()} registros no swooleTable.", 'green');
            },
        ];
        Coroutine::create(fn() => cli::menuCallback($menuCallback));
    }
}