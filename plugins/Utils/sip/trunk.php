<?php

use Plugin\Utils\cli;
use plugin\Utils\network;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;


class trunk
{

    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public Socket $socket;
    public int $expires;
    public string $localIp;
    public string $callId;
    public int $timestamp = 0;
    public int $audioReceivePort;
    public string $nonce;
    public bool $isRegistered = false;
    public int $csq;
    public int $ssrc;
    public bool $receiveBye;
    public bool|array $headers200;

    public string $calledNumber;
    public array $dtmfCallbacks = [];

    public float $lastTime = 0;
    public string $sequenceCodes = '';
    public string $callerId;
    public array $dtmfClicks = [];
    public array $asteriskCodes = [];
    public bool $c180;
    public bool $error;
    public $onAnswerCallback;
    public $onRingFailureCallback;
    public bool $enableAudioRecording;
    public string $audioRecordingFile = '';
    public string $recordAudioBuffer = '';
    public array $dtmfList = [];
    public int $sequenceNumber = 0;
    public int $trys = 0;
    public int $registerCount;
    public int $startSilenceTimer = 0;
    public int $silenceTimer = 0;
    public array $silenceVolume = [];
    public $onTimeoutCallback;
    public bool|string $musicOnSilence = false;
    public bool $disableSilence;
    public $onFailureCallback;
    private int $len;
    private int $timeoutCall;
    private string $sendAudioAddressPath;

    /**
     * @throws \Random\RandomException
     */
    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060)
    {
        $this->username = $username;
        $this->callerId = $username;
        $this->password = $password;
        $this->audioReceivePort = 0;
        $this->host = gethostbyname($host);
        $this->port = $port;
        $this->expires = 300;
        $this->timeoutCall = time();
        $this->localIp = network::getLocalIp();
        $this->csq = rand(100, 99999);
        $this->receiveBye = false;
        $this->headers200 = false;
        $this->calledNumber = '';
        $this->ssrc = random_int(0, 0xFFFFFFFF);
        $this->callId = bin2hex(random_bytes(16));
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        $this->socket->connect($this->host, $this->port);
        $this->c180 = false;
        $this->error = false;
        $this->onAnswerCallback = false;
        $this->onFailureCallback = false;
        $this->onRingFailureCallback = false;
        $this->enableAudioRecording = false;
        $this->recordAudioBuffer = '';
        $this->dtmfList = [];
        $this->registerCount = 0;
        $this->startSilenceTimer = 0;
        $this->silenceTimer = 0;
        $this->silenceVolume = [];
        $this->disableSilence = false;
        $this->sendAudioAddressPath = false;
    }

    public static function staticExtractDtmfEvent(string $payload, array &$clicks = []): void
    {
        $event = ord($payload[0]);
        $volume = ord($payload[1]);
        $duration = unpack('n', substr($payload, 2, 2))[1];
        if (array_key_exists($event, $clicks) && $clicks[$event] > microtime(true) - 0.5) return;
        $clicks[$event] = microtime(true);
        if (($duration < 400)) print cli::color('white', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");

    }

    public static function getAutomaticExtension(): ?array
    {
        $clients = self::getClients();
        $clientsAutomatic = [];
        foreach ($clients as $client => $data) {
            if (array_key_exists('type', $data))
                if ($data['type'] === 'automatic') $clientsAutomatic[] = $client;
        }
        if (count($clientsAutomatic) === 0) {
            sleep(1);
            return self::getAutomaticExtension();
        }
        $rand = array_rand(array_keys($clientsAutomatic));
        $user = $clientsAutomatic[$rand];
        return $clients[$user];
    }

    public static function getClients(): array
    {
        $connectionsFile = \Extension\plugins\utils::baseDir() . 'database.json';
        while (true) {
            try {
                $clients = json_decode(file_get_contents($connectionsFile), true);
                if (!is_array($clients)) {
                    sleep(1);
                } else {
                    return $clients;
                }
            } catch (Exception $e) {
                sleep(1);
            }
        }
    }

    public static function callIsTrunked(string $callId)
    {
        /** @var ObjectProxy $sop */
        $sop = \plugins\Utils\cache::global()['swooleObjectProxy'];
        if ($sop->isset($callId)) return true;
        return false;
    }

    public static function userIsTrunked(string $user)
    {
        $try = sip::getTrunkByUserFromDatabase($user);
        if (!$try) return false;
        if ($try['trunk']['r']) return false;
        return true;
    }

    public function setCallerId(string $callerId): void
    {
        $this->callerId = $callerId;
    }

    public function asteriskRegisterCode(string $code, callable $callback): void
    {
        $this->asteriskCodes[$code] = $callback;
    }

    public function logout(): bool
    {
        $modelRegister = $this->modelRegister();
        $modelRegister['headers']['Expires'] = ['0'];
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        $this->isRegistered = false;
        return true;
    }

    private function modelRegister(): array
    {
        return [
            "method" => "REGISTER",
            "methodForParser" => "REGISTER sip:{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:5060;branch=z9hG4bK-" . bin2hex(random_bytes(4))],
                "From" => ["<sip:{$this->username}@{$this->host}>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:{$this->username}@{$this->host}>"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " REGISTER"],
                "Contact" => ["<sip:{$this->username}@{$this->localIp}>"],
                "User-Agent" => ["spechSoftPhone"],
                "Expires" => [$this->expires],
                "Content-Length" => ["0"],
            ],
        ];
    }

    public function onFail(callable $callback): void
    {
        $this->onFailureCallback = $callback;
    }

    public function call(string $to, $callerId = false): ?bool
    {
        return Coroutine::create(function () use ($to, $callerId) {
            if (!$this->isRegistered) {
                print cli::color('magenta', "Phone not registered") . PHP_EOL;
                $this->register();
                sleep(1);
                if (!$this->isRegistered) {
                    print cli::color('bold_red', "Phone not registered") . PHP_EOL;
                    return false;
                }
            }
            if ($this->trys > 3) {
                return false;
            }

            $this->len = 0;
            $this->receiveBye = false;
            $this->calledNumber = $to;
            if ($this->trys > 0) {
                print cli::color('bold_yellow', "Rediscagem aplicada") . PHP_EOL;
            }
            $this->audioReceivePort = \plugin\Utils\network::getFreePort();
            $modelInvite = $this->modelInvite($to);
            if ($callerId) {
                $fromTmp = sip::extractURI($modelInvite['headers']['From'][0]);
                $fromTmp['user'] = $callerId;
                $modelInvite['headers']['From'][0] = sip::renderURI($fromTmp);
            }
            $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelInvite));
            for (; ;) {
                if ($this->receiveBye) {
                    return true;
                }
                $res = $this->socket->recvfrom($peer, 0.1);
                if (!$res) continue;
                else $receive = sip::parse($res);
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;
                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    $this->asteriskCodes[$causeCode]($receive);
                }
                if ($receive['method'] == 'OPTIONS') $this->socket->sendto($this->host, $this->port, \handlers\renderMessages::respondOptions($receive['headers']));
                if ($receive['method'] == '401') break;
                if ($receive['method'] == '407') break;
                if ($receive['method'] == '200') break;
                if ($receive['method'] == '486') $this->receiveBye = true;
                if ($receive['method'] == '487') $this->receiveBye = true;
                if ($receive['method'] == '403') $this->receiveBye = true;
            }
            $this->ssrc = random_int(0, 0xFFFFFFFF);


            if ($receive['method'] == '401') {
                $this->csq++;
                if (!array_key_exists('WWW-Authenticate', $receive['headers'])) {
                    $this->error = true;
                    $this->receiveBye = true;
                    $this->bye();
                    print cli::color('bold_red', "Chamada para $to não funcionará 243 www-auth") . PHP_EOL;
                    if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                }
                $wwwAuthenticate = $receive['headers']['WWW-Authenticate'][0];
                $nonce = value($wwwAuthenticate, 'nonce="', '"');
                $realm = value($wwwAuthenticate, 'realm="', '"');
                $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:@{$this->localIp}", "INVITE");
                $modelInvite['headers']['Authorization'] = [$authorization];
                $modelInvite['headers']['CSeq'] = ["{$this->csq} INVITE"];
                $dataAuthorization = sip::renderSolution($modelInvite);
                $this->socket->sendto($this->host, $this->port, $dataAuthorization);
            } else {
                $this->csq++;
                if (!array_key_exists('Proxy-Authenticate', $receive['headers'])) {
                    $this->error = true;
                    $this->receiveBye = true;
                    $this->bye();
                    if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                }
                $wwwAuthenticate = $receive['headers']['Proxy-Authenticate'][0];
                $realm = value($wwwAuthenticate, 'realm="', '"');
                $nonce = value($wwwAuthenticate, 'nonce="', '"');
                $qop = value($wwwAuthenticate, 'qop="', '"');
                $responseProxy = sip::generateResponseProxy(
                    $this->username,
                    $this->password,
                    $realm,
                    $nonce,
                    "sip:{$to}@{$this->host}",
                    "INVITE",
                    $qop
                );
                $modelInvite['headers']['Proxy-Authorization'] = [$responseProxy];
                $modelInvite['headers']['CSeq'] = ["{$this->csq} INVITE"];
                $dataAuthorization = sip::renderSolution($modelInvite);
                $this->socket->sendto($this->host, $this->port, $dataAuthorization);
            }
            print cli::color('bold_green', "Chamada para $to funcionará") . PHP_EOL;


            // se demorar mais de 3 segundos para responder, desliga a chamada e liga novamente
            $maxTime = time() + 5;
            for (; ;) {
                if ($this->receiveBye) {
                    if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                    return true;
                }
                $res = $this->socket->recvfrom($peer, 1);
                if (!$res) continue;
                else $receive = sip::parse($res);

                if (!array_key_exists('Call-ID', $receive['headers'])) {
                    $receive['headers']['Call-ID'][0] = $receive['headers']['i'][0];
                }
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;
                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    //$this->asteriskCodes[$causeCode]($receive);
                }
                if ($receive['method'] == 'OPTIONS') $this->socket->sendto($this->host, $this->port, \handlers\renderMessages::respondOptions($receive['headers']));
                if ($receive['method'] == '403') {
                    $this->receiveBye = true;
                    $this->error = true;
                    if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                }
                if ($receive['method'] == '404') $this->receiveBye = true;
                if ($receive['method'] == '486') $this->receiveBye = true;
                if ($receive['method'] == '487') $this->receiveBye = true;
                if ($receive['method'] == '503') {
                    $this->receiveBye = true;
                    $this->error = true;
                    if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                }
                if ($receive['method'] == '200') break;
                if ($receive['method'] == '180' or $receive['method'] == '183') {
                    if (!$this->c180) {
                        $this->c180 = true;
                    }
                }
                if (!$this->c180) {
                    if (time() > $maxTime) {
                        $this->bye();
                        $this->receiveBye = true;
                        if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
                        if (is_callable($this->onRingFailureCallback)) return go($this->onRingFailureCallback);
                        $this->error = true;
                        return false;
                    }
                }
            }

            $uriFromRender = sip::extractURI($modelInvite['headers']['From'][0]);
            $uriFromRender['user'] = $this->username;
            $uriFromRender = sip::renderURI($uriFromRender);
            $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$to}@{$this->host}", "ACK");
            $ackModel = $this->modelAckInvite($receive, $to, $uriFromRender, $authorization);
            $view200 = false;
            for (; ;) {
                break;
                $re = $this->socket->recvfrom($peer, 0.01);
                if ($this->receiveBye) {
                    return true;
                }
                if (!$re) {
                    continue;
                } else {
                    $receive = sip::parse($re);
                }
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;


                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    $this->asteriskCodes[$causeCode]($receive);
                }
                if ($receive['method'] == '200') {
                    $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$to}@{$this->host}", "ACK");
                    $ackModel['headers']['Authorization'] = [$authorization];
                    $this->socket->sendto($this->host, $this->port, sip::renderSolution($ackModel));
                    break;
                }
            }

            $this->headers200 = $receive;
            if (!$this->c180) {
                $this->bye();
                if (is_callable($this->onRingFailureCallback)) return go($this->onRingFailureCallback);
                $this->error = true;
                $this->receiveBye = true;
                return false;
            }


            $mediaIp = $receive['sdp']['c'][0];
            $mediaIp = explode(' ', $mediaIp)[2];
            $mediaPort = explode(' ', $receive['sdp']['m'][0])[1];
            $this->timeoutCall = time();
            $this->resetTimeout();
            print cli::color('bold_green', "Chamada para $to atendida") . PHP_EOL;


            if (is_callable($this->onAnswerCallback)) go($this->onAnswerCallback);


            go(function () use ($mediaIp, $mediaPort) {
                print cli::cl('bold_green', "Sending audio to $mediaIp:$mediaPort");
                $this->sendSilence($mediaIp, $mediaPort);
            });

            go(function () use ($mediaIp, $mediaPort) {
                print cli::cl('bold_green', "Receiving audio from $mediaIp:$mediaPort");
                $this->receiveAudio($mediaPort);
            });


            for (; ;) {
                $res = $this->socket->recvfrom($peer, 0.1);
                if ($this->receiveBye) {
                    return true;
                }
                if (!$res) {
                    continue;
                } else $receive = sip::parse($res);
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;
                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    if (array_key_exists($causeCode, $this->asteriskCodes)) {
                        $this->asteriskCodes[$causeCode]($receive);
                    }
                }

                if (($receive['method'] == 'BYE') and ($receive['headers']['Call-ID'][0] == $this->callId)) {
                    $this->receiveBye = true;
                    if ($this->enableAudioRecording) $this->saveRtpBufferAsWav();


                    print cli::color('bold_red', "receiveBye veio do outro lado") . PHP_EOL;
                    print cli::color('bold_red', "CallID: {$receive['headers']['Call-ID'][0]} -> $this->callId") . PHP_EOL;
                    return true;
                } else if (($receive['method'] == '202') and ($receive['headers']['Call-ID'][0] == $this->callId)) {
                    Coroutine::sleep(1);
                    $this->receiveBye = true;
                    print cli::color('bold_red', "CallID: {$receive['headers']['Call-ID'][0]} -> $this->callId") . PHP_EOL;
                    print cli::color('bold_red', "receiveBye has set 6  $this->calledNumber") . PHP_EOL;
                    break;
                } else if ($this->receiveBye) {
                    return true;
                } else {
                    print $receive['methodForParser'] . " - " . $receive['headers']['Call-ID'][0] . PHP_EOL;
                }
            }

            $this->receiveBye = true;
            return true;
        });
    }

    public function register(): bool|Exception
    {
        if ($this->registerCount > 3) {
            return false;
        }
        $modelRegister = $this->modelRegister();
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        $receive = sip::parse($this->socket->recv());
        if (!array_key_exists('headers', $receive)) {
            print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
        if (!array_key_exists('WWW-Authenticate', $receive['headers'])) {
            print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }


        $wwwAuthenticate = $receive['headers']['WWW-Authenticate'][0];
        $nonce = value($wwwAuthenticate, 'nonce="', '"');
        $realm = value($wwwAuthenticate, 'realm="', '"');
        $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$this->host}", "REGISTER");
        $modelRegister['headers']['Authorization'] = [$authorization];
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        for (; ;) {
            if ($this->receiveBye) {
                return true;
            }
            $rec = $this->socket->recvfrom($peer, 0.1);
            if (!$rec) {
                continue;
            } else {
                $receive = sip::parse($rec);
            }
            if ($receive['method'] == 'OPTIONS') {
                $respond = \handlers\renderMessages::respondOptions($receive['headers']);
                $this->socket->sendto($this->host, $this->port, $respond);
            } else {
                if ($receive['headers']['Call-ID'][0] !== $this->callId) {
                    continue;
                } else {
                    break;
                }
            }
        }
        if ($receive['method'] == '200') {
            $this->csq++;
            $this->nonce = $nonce;
            $this->isRegistered = true;
            return true;
        } else {
            print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
    }

    public function modelInvite(string $to): array
    {
        $sdp = [
            'v' => ['0'],
            'o' => ["{$this->username} 0 0 IN IP4 {$this->localIp}"],
            's' => ['spechSoftPhone'],
            'c' => ['IN IP4 ' . $this->localIp],
            't' => ['0 0'],
            'm' => ['audio ' . $this->audioReceivePort . ' RTP/AVP 0 101'],
            'a' => [
                'rtpmap:0 PCMU/8000',
                //'rtpmap:8 PCMA/8000',
                'rtpmap:101 telephone-event/8000',
                'fmtp:101 0-15',
                'sendrecv'
            ]
        ];

        return [
            "method" => "INVITE",
            "methodForParser" => "INVITE sip:{$to}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:5060;branch=z9hG4bK-" . bin2hex(random_bytes(8))],
                "Max-Forwards" => ["70"],
                "From" => ["<sip:{$this->callerId}@{$this->host}>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:{$to}@{$this->host}>"],
                "Allow" => ["INVITE,ACK,BYE,CANCEL,OPTIONS,NOTIFY,INFO,MESSAGE,UPDATE,REFER"],
                "Supported" => ["gruu,replaces,norefersub"],
                "User-Agent" => ["spechSoftPhone"],
                "Call-ID" => [$this->callId],
                "Contact" => [sip::renderURI(['user' => $this->username, 'peer' => ['host' => $this->localIp, 'port' => 5060]])],
                "CSeq" => [$this->csq . " INVITE"],
                "Content-Type" => ["application/sdp"],
            ],
            "sdp" => $sdp
        ];
    }

    public function bye($registerEvent = true): void
    {
        if (!$this->isRegistered) {
            if ($registerEvent) $this->receiveBye = true;
            return;
        }
        if (!is_array($this->headers200)) {
            if ($registerEvent) $this->receiveBye = true;
            $render = self::getModelCancel();
            $this->socket->sendto($this->host, $this->port, sip::renderSolution($render));
            return;
        }


        $callData = $this->headers200;
        $byeNumber = sip::extractURI($callData['headers']['From'][0])['user'];
        $from = $callData['headers']['From'][0];
        $from = sip::extractURI($from);
        $from['user'] = $this->username;
        $from = sip::renderURI($from);
        $to = $callData['headers']['To'][0];
        $authorization = sip::generateAuthorizationHeader($this->username, $this->nonce, $this->password, "sip:$byeNumber@$this->localIp", "sip:$byeNumber@$this->host", "BYE");
        $byeModel = \handlers\renderMessages::modelBye($byeNumber, $this->callId, $this->localIp, $from, $to, $this->csq, $authorization);
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($byeModel));
        if ($registerEvent) $this->receiveBye = true;
        print cli::color('bold_red', "receiveBye has set 8 $this->calledNumber") . PHP_EOL;
    }

    public function getModelCancel(): array
    {
        return [
            "method" => "CANCEL",
            "methodForParser" => "CANCEL sip:{$this->calledNumber}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:5060;branch=z9hG4bK-" . bin2hex(random_bytes(4))],
                "From" => ["<sip:{$this->username}@{$this->host}>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:{$this->calledNumber}@{$this->host}>"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " CANCEL"],
                "Content-Length" => ["0"],
            ],
        ];
    }

    private function modelAckInvite(array $headers, $to, $uriFromRender, $authorization): array
    {
        $toRender = sip::extractURI($headers['headers']['To'][0]);
        $toRender['user'] = $to;
        $toRender = sip::renderURI($toRender);
        return [
            "method" => "ACK",
            "methodForParser" => "ACK sip:{$to}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => $headers['headers']['Via'],
                "From" => [$uriFromRender],
                "To" => [$toRender],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " ACK"],
                "Authorization" => [$authorization],
                "User-Agent" => ["spechSoftPhone"],
                "Content-Length" => ["0"],
            ],
        ];
    }

    public function resetTimeout(): void
    {
        $this->timeoutCall = time();
    }

    public function sendSilence(string $remoteIp, int $remotePort): bool
    {
        return Coroutine::create(function () use ($remoteIp, $remotePort) {
            $rtpSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
            $timestamp = 0;
            go(function () use ($remotePort, $remoteIp, $rtpSocket) {
                while (!$this->receiveBye) {
                    if ($this->disableSilence) return;
                    if ($this->error) return;
                    $packet = $rtpSocket->recvfrom($peer, 0.1);

                    if ($packet) {
                        $this->processRtpPacket($packet);
                    }
                }
            });

            while (!$this->receiveBye) {
                $frame = str_repeat("\x00", 320);
                $pcmuPayload = $this->PCMToPCMUConverter($frame);
                $rtpHeader = pack('CCnNN', 0x80, 0x00, $this->sequenceNumber++, $timestamp, 0x12345678);
                $timestamp += 160;
                $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
                co::sleep(0.02);
                if (!empty($this->dtmfList)) {
                    $dtmf = array_shift($this->dtmfList);
                    for ($i = 0; $i < 3; $i++) {
                        $endOfEvent = ($i === 2);
                        $dtmfPayload = $this->generateDtmfPacket($dtmf, $endOfEvent);
                        $rtpSocket->sendto($remoteIp, $remotePort, $dtmfPayload);
                        co::sleep(0.02);
                    }
                }
            }
            return true;
        });
    }

    public function processRtpPacket(string $packet): void
    {
        $rtpHeader = substr($packet, 0, 12);
        if ($this->enableAudioRecording) {
            $this->recordAudioBuffer .= substr($packet, 12);
        }
        if ($this->startSilenceTimer > 0) {
            $endSilenceTimer = $this->startSilenceTimer + $this->silenceTimer;
            if (time() < $endSilenceTimer) {
                $rawVolume = ord(substr($packet, 12, 1));
                if ($rawVolume == 255) $rawVolume = 127;
                $volume = $rawVolume & 0x7F;
                $average = 100 - (100 * log($volume + 1) / log(256));
                $lastTime = end($this->silenceVolume);
                if ($lastTime < time() - 1) {
                    $currentSecond = time();
                    $currentSecond = $currentSecond - ($currentSecond % 1);
                    $this->silenceVolume[$currentSecond] = $average;
                }
            }
        }
    }

    public function PCMToPCMUConverter(string $pcmData): string
    {
        $pcmuData = '';
        foreach (str_split($pcmData, 2) as $sample) {
            if (strlen($sample) < 2) {
                continue;
            }

            $pcm = unpack('s', $sample)[1];
            $pcmuData .= chr($this->linearToPCMU($pcm));
        }
        return $pcmuData;
    }

    public function linearToPCMU(int $pcm): int
    {
        $sign = ($pcm < 0) ? 0x80 : 0;
        if ($sign) {
            $pcm = -$pcm;
        }
        if ($pcm > 32635) {
            $pcm = 32635;
        }
        $pcm += 132;
        $exponent = 7;
        for ($mask = 0x4000; ($pcm & $mask) === 0 && $exponent > 0; $mask >>= 1) {
            $exponent--;
        }
        $mantissa = ($pcm >> (($exponent == 0) ? 4 : ($exponent + 3))) & 0x0F;
        return (~($sign | ($exponent << 4) | $mantissa)) & 0xFF;
    }

    public function generateDtmfPacket(mixed $dtmf, bool $endOfEvent = false, int $volume = 0x40, int $duration = 160): string
    {
        $payloadType = 101; // Confirme se este é o correto no SDP
        $ssrc = 0x12345678;

        $rtpHeader = pack('CCnNN',
            0x80, // RTP v2
            $payloadType, // Payload Type do DTMF (101)
            $this->sequenceNumber++,
            $this->timestamp,
            $ssrc
        );

        $this->timestamp += 160; // Avança o tempo do RTP

        // Criando o Payload do DTMF
        $dtmfPayload = pack('CCCC',
            $dtmf,         // Código do dígito DTMF (0-9, *, #, A-D)
            $volume,       // Volume do tom
            ($duration >> 8), // Parte alta da duração
            ($duration & 0xFF) // Parte baixa da duração
        );

        // Garante que o último pacote tenha o bit "End of Event" ativado
        if ($endOfEvent) {
            $dtmfPayload[3] = chr(ord($dtmfPayload[3]) | 0x80);
        }

        return $rtpHeader . $dtmfPayload;
    }

    public function receiveAudio(mixed $port): bool
    {
        return Coroutine::create(function () use ($port) {
            $this->lastTime = time();
            $socket = new Socket(AF_INET, SOCK_DGRAM, 0);
            if (!$socket->bind('0.0.0.0', $port)) {
                echo "Failed to bind to $port";
                return false;
            } else {
                print cli::color('blue', "Proxy iniciado na porta $port") . PHP_EOL;
            }
            $lastIp = [];
            while (!$this->receiveBye) {
                if ($this->error) return false;
                $packet = $socket->recvfrom($peer, 0.1);
                if ($packet) {
                    $lastIp = $peer;
                    $this->processRtpPacket($packet);
                }
                if (!empty($this->dtmfList)) {
                    $dtmf = array_shift($this->dtmfList);
                    for ($i = 0; $i < 3; $i++) {
                        $endOfEvent = ($i === 2);
                        $dtmfPayload = $this->generateDtmfPacket($dtmf, $endOfEvent);
                        if (array_key_exists('address', $lastIp) && array_key_exists('port', $lastIp)) $socket->sendto($lastIp['address'], $lastIp['port'], $dtmfPayload);


                        co::sleep(0.02);
                    }
                }
            }
            return true;
        });
    }

    public function saveRtpBufferAsWav(int $sampleRate = 8000): ?string
    {
        if (!$this->enableAudioRecording) {
            echo "Erro: Gravação de áudio não habilitada.\n";
            return false;
        }
        if (strlen($this->recordAudioBuffer) > 0) {
            $sampleRate = 8000;
            $channels = 1;
            $wavDataPoloBase = generateWavHeader(strlen($this->recordAudioBuffer), $sampleRate, $channels, 16);
            $wavData = $wavDataPoloBase . $this->recordAudioBuffer;
            file_put_contents($this->audioRecordingFile, $wavData);
            echo "Áudio gravado com sucesso em {$this->audioRecordingFile}.\n";
            return $this->audioRecordingFile;
        } else {
            echo "Erro: Nenhum áudio gravado.\n";
            return false;
        }
    }

    public function sendAudio(string $filePath, string $remoteIp, int $remotePort): ?bool
    {
        return Coroutine::create(function () use ($filePath, $remoteIp, $remotePort) {
            $trueFormat = $this->detectFileFormatFromHeader($filePath);
            if ($trueFormat !== pathinfo($filePath, PATHINFO_EXTENSION)) {
                $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.' . $trueFormat;
                rename($filePath, $newFilePath);
                $filePath = $newFilePath;
            }
            if (!file_exists($filePath) || !is_readable($filePath)) {
                throw new Exception("Erro: Arquivo de áudio inválido ou não pode ser lido: $filePath");
            }
            $file = fopen($filePath, 'rb');
            if (!$file) {
                throw new Exception("Erro ao abrir o arquivo WAV: $filePath");
            }
            $header = fread($file, 44);
            fclose($file);
            if (!str_starts_with($header, "RIFF") || substr($header, 8, 4) !== "WAVE") {
                $filePath = $this->convertToWav($filePath);
            }
            $file = fopen($filePath, 'rb');
            $header = fread($file, 44);
            $sampleRate = unpack('V', substr($header, 24, 4))[1];
            $channels = unpack('v', substr($header, 22, 2))[1];
            $bitsPerSample = unpack('v', substr($header, 34, 2))[1];
            if ($bitsPerSample !== 16 || $channels !== 1 || $sampleRate !== 8000) {
                $filePath = $this->convertToWav($filePath, 8000, 1, 16);
            }
            fclose($file);
            return $this->sendRtpAudio($filePath, $remoteIp, $remotePort);
        });
    }

    private function detectFileFormatFromHeader(string $filePath): string
    {
        $file = fopen($filePath, 'rb');
        if (!$file) {
            throw new Exception("Erro ao abrir o arquivo para detecção de formato: $filePath");
        }

        $header = fread($file, 12);
        fclose($file);

        // Verifica o formato com base no cabeçalho
        if (str_starts_with($header, "ID3")) {
            return 'mp3';
        } elseif (str_starts_with($header, "RIFF") && substr($header, 8, 4) === "WAVE") {
            return 'wav';
        } elseif (str_starts_with($header, "OggS")) {
            return 'ogg';
        } elseif (str_starts_with($header, "fLaC")) {
            return 'flac';
        } elseif (substr($header, 4, 4) === "ftyp") {
            if (str_contains($header, "isom") || str_contains($header, "mp42")) {
                return 'mp4';
            }
            return 'm4a';
        } elseif (str_starts_with($header, "\xFF\xF1") || str_starts_with($header, "\xFF\xF9")) {
            return 'aac';
        }

        return pathinfo($filePath, PATHINFO_EXTENSION); // Retorna extensão padrão se desconhecido
    }

    private function convertToWav(string $filePath, int $sampleRate = 8000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $fileInfo = pathinfo($filePath);
        $newFilePath = $fileInfo['dirname'] . '/' . $fileInfo['filename'] . '_converted.wav';

        // Garante que o novo arquivo não exista antes da conversão
        if (file_exists($newFilePath)) {
            unlink($newFilePath);
        }

        // Conversão manual para WAV usando biblioteca ou lógica apropriada
        // Esta parte assume que a implementação da conversão está em outro método ou biblioteca.
        $this->manualConvertToWav($filePath, $newFilePath, $sampleRate, $channels, $bitsPerSample);

        if (!file_exists($newFilePath)) {
            throw new Exception("Erro: Falha ao converter o arquivo de áudio para WAV.");
        }

        return $newFilePath;
    }

    private function manualConvertToWav(string $filePath, string $newFilePath, int $sampleRate, int $channels, int $bitsPerSample): void
    {
        $command = sprintf('ffmpeg -i %s -ar %d -ac %d -sample_fmt s%d %s 2>&1', escapeshellarg($filePath), $sampleRate, $channels, $bitsPerSample, escapeshellarg($newFilePath));
        exec($command, $output, $returnCode);
        // se o arquivo não foi criado, então vamos criar um arquivo com silêncio
        if (!file_exists($newFilePath)) {
            $command = sprintf('ffmpeg -f lavfi -i anullsrc=channel_layout=mono:sample_rate=8000 -t 1 %s 2>&1', escapeshellarg($newFilePath));
            exec($command, $output, $returnCode);
        }
        // pronto!


    }

    private function sendRtpAudio(string $filePath, string $remoteIp, int $remotePort): bool
    {
        $file = fopen($filePath, 'rb');
        fread($file, 44);
        $audioData = '';
        while (!feof($file)) {
            $chunk = fread($file, 8192);
            if ($chunk === false) break;
            $audioData .= $chunk;
        }
        fclose($file);

        $frames = str_split($audioData, 320);
        $rtpSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $timestamp = 0;
        go(function () use ($remotePort, $remoteIp, $rtpSocket) {
            while (!$this->receiveBye) {
                $packet = $rtpSocket->recvfrom($peer, 0.1);
                if ($packet) {
                    $this->processRtpPacket($packet);
                }
                if (!empty($this->dtmfList)) {
                    $dtmf = array_shift($this->dtmfList);
                    for ($i = 0; $i < 3; $i++) {
                        $endOfEvent = ($i === 2);
                        $dtmfPayload = $this->generateDtmfPacket($dtmf, $endOfEvent);
                        $rtpSocket->sendto($remoteIp, $remotePort, $dtmfPayload);
                        Coroutine::sleep(0.02);
                    }
                }
            }
        });
        foreach ($frames as $frame) {
            if ($this->receiveBye) break;
            $pcmuPayload = $this->PCMToPCMUConverter($frame);
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $this->sequenceNumber++, $timestamp, 0x12345678);
            $timestamp += 160;
            $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
            Coroutine::sleep(0.02);
        }
        // enviar som não perceptivel ao ouvido para não cortar a chamada
        while (!$this->receiveBye) {
            $frame = str_repeat("\x00", 320);
            $pcmuPayload = $this->PCMToPCMUConverter($frame);
            $timestamp += 160;
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $this->sequenceNumber++, $timestamp, 0x12345678);
            $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
            Coroutine::sleep(0.02);
        }
        return true;
    }

    public function detectSilenceTimer(int $seconds): bool
    {
        $this->startSilenceTimer = time();
        $this->silenceTimer = $seconds;
        $this->silenceVolume = [];

        while (time() < $this->startSilenceTimer + $seconds) {
            if ($this->receiveBye) return true;
            if ($this->disableSilence) return false;
            if ($this->error) return false;
            usleep(100000);
        }
        $vs = array_values($this->silenceVolume);
        $uniques = array_unique($vs);
        $na = [];
        foreach ($uniques as $volume)
            $na[round($volume - 0.1)] = count(array_keys($vs, $volume));

        if (count($na) < 13) return true;
        $mv = max(array_values($na));
        $key = array_search($mv, $na);
        if ($key < 13) return true;
        return false;
    }

    public function disableSilence()
    {
        $this->disableSilence = true;
    }

    public function setAudio(string $string)
    {
        $this->sendAudioAddressPath = $string;
    }

    public function record(string $string): void
    {
        $this->enableAudioRecording = true;
        $this->recordAudioBuffer = '';
        $this->audioRecordingFile = $string;
    }

    public function sendDtmf(string $dtmf): bool
    {
        Coroutine::sleep(0.1); // Pequena pausa antes do envio
        $this->dtmfList[] = $dtmf;

        // Aguarda até que todos os DTMFs na lista sejam processados
        while (!empty($this->dtmfList)) {
            Coroutine::sleep(0.05); // Pequeno delay para evitar loop excessivo
        }

        return true; // Retorna true somente quando todos os DTMFs forem enviados
    }

    public function onAnswer($param)
    {
        $this->onAnswerCallback = $param;
    }

    public function onTimeout($param)
    {
        $this->onTimeoutCallback = $param;
    }

    public function extractDtmfEvent(string $payload): void
    {
        $event = ord($payload[0]);
        $volume = ord($payload[1]);
        $duration = unpack('n', substr($payload, 2, 2))[1];
        if (array_key_exists($event, $this->dtmfClicks) && $this->dtmfClicks[$event] > microtime(true) - 0.5) return;
        $this->dtmfClicks[$event] = microtime(true);
        if (($duration < 400)) {
            if (isset($this->dtmfCallbacks[$event])) $this->dtmfCallbacks[$event]();
            print cli::color('white', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        } else {
            print cli::color('bold_red', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        }
    }

    public function registerDtmfCallback(string $dtmf, callable $callback): void
    {
        $this->dtmfCallbacks[$dtmf] = $callback;
    }

    public function convertStereoToMono(string $audioData): string
    {
        $monoData = '';
        $samples = str_split($audioData, 4);

        foreach ($samples as $sample) {
            if (strlen($sample) < 4) {
                continue;
            }

            $left = unpack('s', substr($sample, 0, 2))[1];
            $right = unpack('s', substr($sample, 2, 2))[1];

            $monoSample = (int)(($left + $right) / 2); // Média dos canais
            $monoData .= pack('s', $monoSample);
        }

        return $monoData;
    }

    public function resample(string $data, int $sourceRate): string
    {
        $sourceSamples = strlen($data) / 2;
        $targetSamples = (int)(($sourceSamples * 8000) / $sourceRate);

        $resampledData = '';
        for ($i = 0; $i < $targetSamples; $i++) {
            $sourceIndex = ($i * $sourceRate) / 8000;
            $low = (int)floor($sourceIndex);
            $high = (int)ceil($sourceIndex);

            $lowSample = unpack('s', substr($data, $low * 2, 2))[1] ?? 0;
            $highSample = unpack('s', substr($data, $high * 2, 2))[1] ?? $lowSample;

            $alpha = $sourceIndex - $low;
            $interpolatedSample = (int)(($lowSample * (1 - $alpha)) + ($highSample * $alpha));

            $resampledData .= pack('s', $interpolatedSample);
        }

        return $resampledData;
    }

    public function transfer(string $to): ?bool
    {
        $originTo = $this->calledNumber;
        $modelRefer = [
            "method" => "REFER",
            "methodForParser" => "REFER sip:$originTo@$this->host SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP $this->localIp:5060;branch=z9hG4bK-" . bin2hex(random_bytes(4))],
                "From" => ["<sip:$this->username@$this->host>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:$originTo@$this->host>"],
                "Call-ID" => [$this->callId],
                "Event" => ["refer"],
                "CSeq" => [$this->csq . " REFER"],
                "Contact" => ["<sip:$this->username@$this->localIp>"],
                "Refer-To" => ["sip:$to@$this->host"],
                "Referred-By" => ["sip:$this->username@$this->host"],
                "Content-Length" => ["0"],
            ],
        ];
        return $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRefer));
    }

    public function defineTimeout(int $seconds): ?bool
    {
        return Coroutine::create(function () use ($seconds) {
            while ((time() - $this->timeoutCall) < $seconds) {
                if ($this->receiveBye) {
                    if ($this->error) break;
                    print cli::color('bold_red', "receiveBye has receive in timeout capured in timeout") . PHP_EOL;
                    break;
                }
                Coroutine::sleep(0.1);
            }
            // se deu timeout entao chama o onFailure
            if ((time() - $this->timeoutCall) >= $seconds) {
                print cli::cl('white', "Timeout has not reached " . $seconds . " seconds");
                print cli::cl('white', "CallID: $this->callId");
                print cli::cl('white', "==========================================================================================" . PHP_EOL);
                if (is_callable($this->onFailureCallback)) return go($this->onFailureCallback);
            }


            if (!$this->receiveBye) print cli::color('bold_red', "Timeout has reached " . $seconds . " seconds") . PHP_EOL;


            try {
                print cli::color('yellow', "Bye sent by timeout") . PHP_EOL;
                $this->bye();
            } catch (Exception $e) {
                $this->receiveBye = true;
                print cli::color('bold_red', "Error: " . $e->getMessage()) . PHP_EOL;
            }
            $this->receiveBye = true;
            $callable = $this->onTimeoutCallback;
            if (is_callable($callable)) $callable();
            return true;
        });
    }

    public function onRingFailure(callable $callable): void
    {
        $this->onRingFailureCallback = $callable;
    }

    private function internalSilenceDetect()
    {

    }
}