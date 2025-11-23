<?php /** @noinspection DuplicatedCode */

use Plugin\Utils\cli;
use plugin\Utils\network;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;


class phone
{
    public static array $supportedCodecStatic = [
        0 => [
            'rtpmap:0 PCMU/8000',
            'fmtp:0 0-160',

        ],
        18 => [
            'rtpmap:18 G729/8000',
            'fmtp:18 annexb=no',

        ],
        101 => [
            'rtpmap:101 telephone-event/8000',
            'fmtp:101 0-16',
        ],
        8 => [
            'rtpmap:8 PCMA/8000',
            'fmtp:8 0-160',

        ],
    ];
    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public int $expires;
    public string $localIp;
    public string $callId;
    public array $supportedCodecs = [
        0 => [
            'rtpmap:0 PCMU/8000',
        ],
        18 => [
            'rtpmap:18 G729/8000',
            'fmtp:18 annexb=no',
        ],
        101 => [
            'rtpmap:101 telephone-event/8000',
            'fmtp:101 0-16',
        ],
        8 => [
            'rtpmap:8 PCMA/8000',
            'fmtp:8 0-160',

        ],
    ];
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
    public bool $enableAudioRecording;
    public string $audioRecordingFile = '';
    public string $recordAudioBuffer = '';
    public array $dtmfList = [];
    public int $sequenceNumber = 0;
    public int $registerCount;
    public $onVoiceCallback;
    public bool $breakAudio = false;
    public int $timestamp = 0;
    public string $audioFilePath = '';
    public bool $silence = false;
    public int $audioPortServer = 0;
    public int $currentCodec = 0;
    public array $listeners = [];
    public mixed $channel = null;
    public Socket $mediaSocket;
    public rtpChannels $rtpChan;
    public array $byeRecovery = [];
    public int $closeCallInTime = 0;
    public int $timeStart = 0;
    public bool $callActive = false;
    public string $audioRemoteIp;
    public int $audioRemotePort;
    public Socket $socket;
    public string $codecMediaLine = '';
    public array $codecRtpMap = [];
    public bool $newSound = false;
    private int $len;
    private int $timeoutCall;

    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060)
    {
        $this->username = $username;
        $this->callerId = $username;
        $this->password = $password;
        $this->audioReceivePort = network::getFreePort();
        $this->host = gethostbyname($host);
        $this->port = $port;
        $this->expires = 300;
        $this->timeoutCall = time();

        $this->closeCallInTime = time() + 720;
        $this->timeStart = time();
        try {
            $this->ssrc = random_int(0, 0xFFFFFFFF);
        } catch (\Random\RandomException $e) {
            $this->ssrc = rand(1, 100000);
        }
        try {
            $this->callId = bin2hex(random_bytes(16));
        } catch (\Random\RandomException $e) {
            $this->callId = bin2hex(time());
        }
        $this->callId = (md5(time()* random_int(9,98877)));
        $this->rtpChan = new rtpChannels(8, 160, $this->ssrc);
        $this->localIp = network::getLocalIp();
        $this->csq = rand(1000, 99999);
        $this->receiveBye = false;
        $this->headers200 = false;
        $this->calledNumber = '';
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        $this->c180 = false;
        $this->error = false;
        $this->onAnswerCallback = false;
        $this->enableAudioRecording = false;
        $this->recordAudioBuffer = '';
        $this->dtmfList = [];
        $this->registerCount = 0;
        $this->onVoiceCallback = false;
        $lastPortDram = $this->audioPortServer;
        $this->defineCodecs([8, 101]);
        $this->mediaSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        go(function () use ($lastPortDram) {
            while (true) {
                if ($this->audioPortServer !== $lastPortDram) {
                    $lastPortDram = $this->audioPortServer;
                    $this->mediaSocket->bind(network::getLocalIp(), $this->audioPortServer);
                }
                if ($this->receiveBye) {
                    // print cli::cl('green', 'bye received!!!!!!');
                    return $this->mediaSocket->close();
                }
                Coroutine::sleep(0.1);
                if (time() > $this->closeCallInTime) {
                    $this->bye();
                    $this->receiveBye = true;
                    $this->socket->close();
                    $this->mediaSocket->close();
                    \callHandler::resolveCloseCall($this->callId, ['bye' => true, 'recovery' => true]);
                    trunkController::resolveCloseCall($this->callId);
                    // print cli::color('red', 'timeout') . PHP_EOL;
                }
            }
        });
    }

    public function defineCodecs(array $codecs = [0, 101]): void
    {
        $codecMediaLine = '';
        $codecRtpMap = [];

        // botar do menor pro maior
        asort($codecs);
        foreach ($codecs as $codec) {
            if (array_key_exists($codec, $this->supportedCodecs)) {
                $codecMediaLine .= "$codec ";
                foreach ($this->supportedCodecs[$codec] as $line) {
                    $codecRtpMap[] = $line;
                }
            }
        }

        $this->codecMediaLine = trim($codecMediaLine);
        $this->codecRtpMap = $codecRtpMap;
        $this->codecRtpMap = array_unique($this->codecRtpMap);
    }

    public function bye($registerEvent = true): void
    {
        if ($this->enableAudioRecording) $this->saveRtpBufferAsWav();
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
        $this->socket->close();
        // print cli::color('bold_red', "receiveBye has set 8 $this->calledNumber") . PHP_EOL;
    }

    public function saveRtpBufferAsWav(int $sampleRate = 8000): void
    {
        if (!$this->enableAudioRecording) {
            echo "Erro: Gravação de áudio não habilitada.\n";
            return;
        }
        if (strlen($this->recordAudioBuffer) > 0) {
            $sampleRate = 8000;
            $channels = 1;
            $wavDataPoloBase = generateWavHeader(strlen($this->recordAudioBuffer), $sampleRate, $channels, 16);
            $wavData = $wavDataPoloBase . $this->recordAudioBuffer;
            file_put_contents($this->audioRecordingFile, $wavData);
            echo "Áudio gravado com sucesso em {$this->audioRecordingFile}.\n";
        } else {
            echo "Erro: Nenhum áudio gravado.\n";
        }
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

    public static function staticSendAudio(string $filePath, string $remoteIp, int $remotePort): ?bool
    {
        return Coroutine::create(function () use ($filePath, $remoteIp, $remotePort) {
            $trueFormat = self::staticDetectFileFormatFromHeader($filePath);
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
                $filePath = self::staticConvertToWav($filePath);
            }
            $file = fopen($filePath, 'rb');
            $header = fread($file, 44);
            $sampleRate = unpack('V', substr($header, 24, 4))[1];
            $channels = unpack('v', substr($header, 22, 2))[1];
            $bitsPerSample = unpack('v', substr($header, 34, 2))[1];
            if ($bitsPerSample !== 16 || $channels !== 1 || $sampleRate !== 8000) {
                $filePath = self::staticConvertToWav($filePath, 8000, 1, 16);
            }
            fclose($file);
            return self::staticSendRtpAudio($filePath, $remoteIp, $remotePort);
        });
    }

    public static function staticDetectFileFormatFromHeader(string $filePath): string
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

    public static function staticConvertToWav(string $filePath, int $sampleRate = 8000, int $channels = 1, int $bitsPerSample = 16): string
    {
        $fileInfo = pathinfo($filePath);
        $newFilePath = $fileInfo['dirname'] . '/' . $fileInfo['filename'] . '_converted.wav';

        // Garante que o novo arquivo não exista antes da conversão
        if (file_exists($newFilePath)) {
            unlink($newFilePath);
        }

        // Conversão manual para WAV usando biblioteca ou lógica apropriada
        // Esta parte assume que a implementação da conversão está em outro método ou biblioteca.
        self::staticManualConvertToWav($filePath, $newFilePath, $sampleRate, $channels, $bitsPerSample);

        if (!file_exists($newFilePath)) {
            throw new Exception("Erro: Falha ao converter o arquivo de áudio para WAV.");
        }

        return $newFilePath;
    }

    public static function staticManualConvertToWav(string $filePath, string $newFilePath, int $sampleRate, int $channels, int $bitsPerSample): void
    {
        $command = sprintf('ffmpeg -i %s -ar %d -ac %d -sample_fmt s%d %s 2>&1', escapeshellarg($filePath), $sampleRate, $channels, $bitsPerSample, escapeshellarg($newFilePath));
        exec($command, $output, $returnCode);
        if (!file_exists($newFilePath)) {
            $command = sprintf('ffmpeg -f lavfi -i anullsrc=channel_layout=mono:sample_rate=8000 -t 1 %s 2>&1', escapeshellarg($newFilePath));
            exec($command, $output, $returnCode);
        }
        // pronto!
    }

    public static function staticSendRtpAudio(string $filePath, string $remoteIp, int $remotePort): bool
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
        $sequenceNumber = 0;


        foreach ($frames as $frame) {
            $pcmuPayload = self::staticPCMToPCMUConverter($frame);
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $sequenceNumber++, $timestamp, 0x12345678);
            $timestamp += 160;
            $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
            Coroutine::sleep(0.02);
        }
        return true;
    }

    public static function staticPCMToPCMUConverter(string $pcmData): string
    {
        $pcmuData = '';
        foreach (str_split($pcmData, 2) as $sample) {
            if (strlen($sample) < 2) {
                continue;
            }

            $pcm = unpack('s', $sample)[1];
            $pcmuData .= chr(self::staticLinearToPCMU($pcm));
        }
        return $pcmuData;
    }

    public static function staticLinearToPCMU(int $pcm): int
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

    public function setTimeout(int $int): void
    {
        $this->closeCallInTime = time() + $int;
    }

    public function defineTimeout(int $seconds): ?bool
    {
        return Coroutine::create(function () use ($seconds) {
            while ((time() - $this->timeoutCall) < $seconds) {
                if ($this->receiveBye) {
                    // print cli::color('bold_red', "receiveBye has receive in timeout") . PHP_EOL;
                    return true;
                }
                Coroutine::sleep(0.1);
            }
            try {
                // print cli::color('yellow', "Bye sent by timeout") . PHP_EOL;
                $this->bye();

            } catch (Exception $e) {
                // print cli::color('bold_red', "Error: " . $e->getMessage()) . PHP_EOL;
            }
            // print cli::color('bold_red', "Timeout has reached " . $seconds . " seconds") . PHP_EOL;
            return true;
        });
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

    public function receiveMedia(): void
    {
        Coroutine::create(function () {
            $this->mediaSocket->bind($this->localIp, $this->audioReceivePort);
            while (!$this->receiveBye) {
                $received = $this->mediaSocket->recvfrom($info, 0.1);
                if ($received) $this->processRtpPacket($received);
            }
        });
        Coroutine::create(function () {
            $frameSize = 320;
            $buffer = str_repeat("\0", $frameSize);
            $lastMicrotime = microtime(true);
            $currentPath = $this->audioFilePath;
            $handle = fopen($currentPath, 'rb');

            if (!$handle) {
                echo "Erro ao abrir $currentPath\n";
                return;
            }

            while (true) {
                // Se chamada terminou ou handler atual perdeu sentido
                if ($this->receiveBye) break;

                // Detecção de troca de arquivo
                if (($this->audioFilePath !== $currentPath) or $this->newSound) {
                    $this->newSound = false;
                    fclose($handle);
                    $currentPath = $this->audioFilePath;
                    $handle = fopen($currentPath, 'rb');
                    if (!$handle) {
                        echo "Erro ao abrir novo arquivo $currentPath\n";
                        break;
                    }
                    echo "Arquivo de áudio trocado dinamicamente para $currentPath\n";
                    continue;
                }

                $read = fread($handle, $frameSize);
                if ($read === false || strlen($read) < $frameSize) {
                    // usa frame silencioso
                    $read = str_repeat("\0", $frameSize);
                }

                foreach ($this->listeners as $listener) {
                    $payloadMatch = match ($this->currentCodec) {
                        18 => $this->channel->encode($read),
                        0 => $this->encodePcmToPcmu($read),
                        8 => $this->encodePcmToPcma($read)
                    };
                    $this->rtpChan->setCodec($this->currentCodec);
                    $packed = $this->rtpChan->build($payloadMatch);
                    $this->mediaSocket->sendto($listener['address'], $listener['port'], $packed);
                }

                $microtime = microtime(true);
                if ($microtime - $lastMicrotime > 0.1) {
                    $findU = $this->username;
                    $this->mediaSocket->sendto(
                        '127.0.0.1',
                        5093,
                        round($this->volumeAverage($read), 2) . "__::__" . $this->callId . "__::__" . $findU
                    );
                    $lastMicrotime = $microtime;
                }

                Coroutine::sleep(0.02);
            }

            fclose($handle);
        });

    }

    public function processRtpPacket(string $packet): void
    {
        $rtpHeader = substr($packet, 0, 12);
        $payloadType = (ord($rtpHeader[1]) & 0x7F);
        $sequenceNumber = unpack('n', substr($rtpHeader, 2, 2))[1];


        if ($this->enableAudioRecording) {
            $this->recordAudioBuffer .= substr($packet, 12);
        }


        if ($payloadType === 101) {
            $this->extractDtmfEvent(substr($packet, 12));
        }
    }

    public function extractDtmfEvent(string $payload): void
    {
        $event = ord($payload[0]);
        $volume = ord($payload[1]);
        $duration = unpack('n', substr($payload, 2, 2))[1];
        if (array_key_exists($event, $this->dtmfClicks) && $this->dtmfClicks[$event] > microtime(true) - 0.5) return;
        $this->dtmfClicks[$event] = microtime(true);
        if (($duration < 400)) {
            if (isset($this->dtmfCallbacks[$event])) {
                if (is_callable($this->dtmfCallbacks[$event])) {
                    $this->dtmfCallbacks[$event]($this);
                }
            }
            // print cli::color('white', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        } else {
            // print cli::color('bold_red', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        }
    }

    public function encodePcmToPcmu(string $data): string
    {
        $encoded = '';
        for ($i = 0; $i < strlen($data); $i += 2) {
            $sample = unpack('v', substr($data, $i, 2))[1];
            if ($sample > 32767) {
                $sample -= 65536;
            }
            $encoded .= chr(linear2ulaw($sample));
        }
        return $encoded;
    }

    public function encodePcmToPcma(string $data): string
    {
        $encoded = '';
        for ($i = 0; $i < strlen($data); $i += 2) {
            $sample = unpack('v', substr($data, $i, 2))[1];
            if ($sample > 32767) {
                $sample -= 65536;
            }
            $encoded .= chr(linear2alaw($sample));
        }
        return $encoded;
    }

    public function volumeAverage(string $pcm): float
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

    public function play(): false|int
    {

        return true;
    }

    public function call(string $to, $callerId = false): ?bool
    {
        return Coroutine::create(function () use ($to, $callerId) {
            if (!$this->isRegistered) {
                // print cli::color('magenta', "Phone not registered") . PHP_EOL;
                $this->register();
                sleep(1);
                if (!$this->isRegistered) {
                    // print cli::color('bold_red', "Phone not registered") . PHP_EOL;
                    return false;
                }
            }

            $this->len = 0;
            $this->receiveBye = false;
            $this->calledNumber = $to;


            $modelInvite = $this->modelInvite($to);
            if ($callerId) {
                $fromTmp = sip::extractURI($modelInvite['headers']['From'][0]);
                $fromTmp['user'] = $callerId;
                $modelInvite['headers']['From'][0] = sip::renderURI($fromTmp);
            }
            $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelInvite));
            for (; ;) {
                if ($this->receiveBye) {
                    // print "Call ended 2 receiveBye" . PHP_EOL;
                    return true;
                }
                $res = $this->socket->recvfrom($peer, 0.01);
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
                if ($receive['method'] == 'CANCEL') $this->receiveBye = true;
                if ($receive['method'] == 'BYE') $this->receiveBye = true;
                if ($receive['method'] == '503') $this->receiveBye = true;
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
                    // print cli::color('bold_red', "Chamada para $to não funcionará") . PHP_EOL;
                    return false;
                }
                $wwwAuthenticate = $receive['headers']['WWW-Authenticate'][0];
                $nonce = value($wwwAuthenticate, 'nonce="', '"');
                $realm = value($wwwAuthenticate, 'realm="', '"');
                $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:@{$this->localIp}", "INVITE");
                $modelInvite['headers']['Authorization'] = [$authorization];
                $modelInvite['headers']['CSeq'] = ["{$this->csq} INVITE"];
                $dataAuthorization = sip::renderSolution($modelInvite);
                $this->socket->sendto($this->host, $this->port, $dataAuthorization);
            } elseif ($receive['method'] == '180') {
                $this->c180 = true;

            } else {
                $this->csq++;
                if (!array_key_exists('Proxy-Authenticate', $receive['headers'])) {
                    $this->error = true;
                    $this->receiveBye = true;
                    $this->bye();
                    print cli::color('bold_red', "Chamada para $to não funcionará proxy-auth") . PHP_EOL;
                    return false;
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
            //var_dump($receive);

            //print cli::color('bold_green', "Chamada para $to realizada") . PHP_EOL;
            $maxTime = time() + 35;
            for (; ;) {
                if ($this->receiveBye) {
                    return true;
                }
                $res = $this->socket->recvfrom($peer, 1);
                if (!$res) continue;
                else $receive = sip::parse($res);
                if ($receive['method'] == 'CANCEL') $this->receiveBye = true;
                if ($receive['method'] == 'BYE') $this->receiveBye = true;
                if ($receive['method'] == '503') $this->receiveBye = true;

                // print $res . PHP_EOL;
                // print $receive['methodForParser'] . " - " . $receive['headers']['Call-ID'][0] . " (" . $receive['headers']['CSeq'][0] . ")" . PHP_EOL;


                if (!array_key_exists('Call-ID', $receive['headers'])) {
                    if (!array_key_exists('i', $receive['headers'])) {
                        continue;
                    }

                    $receive['headers']['Call-ID'][0] = $receive['headers']['i'][0];
                }
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;
                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    $this->asteriskCodes[$causeCode]($receive);
                }
                if ($receive['method'] == 'OPTIONS') $this->socket->sendto($this->host, $this->port, \handlers\renderMessages::respondOptions($receive['headers']));
                if ($receive['method'] == '403') {
                    $this->receiveBye = true;
                    $this->error = true;
                }
                if ($receive['method'] == '404') $this->receiveBye = true;
                if ($receive['method'] == '180') $this->c180 = true;
                if ($receive['method'] == '480') $this->receiveBye = true;
                if ($receive['method'] == '481') $this->receiveBye = true;
                if ($receive['method'] == '486') $this->receiveBye = true;
                if ($receive['method'] == '487') $this->receiveBye = true;
                if ($receive['method'] == '503') {
                    $this->receiveBye = true;
                    // $this->error = true;
                }
                if ($receive['method'] == '200') {
                    $this->headers200 = $receive;

                    // print cli::color('bold_green', "Chamada para $to atendida") . PHP_EOL;
                    break;
                }
                if ($receive['method'] == '180' or $receive['method'] == '183') {
                    $this->c180 = true;
                    if (!$this->c180) {
                        $this->c180 = true;
                        // print cli::color('bold_green', "Chamada para $to funcionará") . PHP_EOL;
                    }
                }
                // print $receive['methodForParser'] . " - " . $receive['headers']['Call-ID'][0] . " (" . $receive['headers']['CSeq'][0] . ")" . PHP_EOL;
                if (!$this->c180)
                    if (time() > $maxTime) {
                        $this->bye(false);
                        return $this->call($to, $callerId);
                    }

            }

            print cli::color('bold_green', "Chamada para $to atendida") . PHP_EOL;


            $uriFromRender = sip::extractURI($modelInvite['headers']['From'][0]);
            $uriFromRender['user'] = $this->username;
            $uriFromRender = sip::renderURI($uriFromRender);
            $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$to}@{$this->host}", "ACK");
            $ackModel = $this->modelAckInvite($receive, $to, $uriFromRender, $authorization);
            $view200 = false;
            $this->receiveBye = false;
            if (!$this->headers200) for (; ;) {
                $re = $this->socket->recvfrom($peer, 0.01);
                if ($this->receiveBye) {
                    print "Call ended 4 receiveBye" . PHP_EOL;
                    return true;
                }
                if (!$re) {
                    continue;
                } else {
                    $receive = sip::parse($re);
                }
                if ($receive['headers']['Call-ID'][0] !== $this->callId) continue;
                if ($receive['method'] == 'CANCEL') $this->receiveBye = true;
                if ($receive['method'] == 'BYE') $this->receiveBye = true;
                if ($receive['method'] == '503') $this->receiveBye = true;


                if (array_key_exists('X-Asterisk-HangupCauseCode', $receive['headers'])) {
                    $causeCode = $receive['headers']['X-Asterisk-HangupCauseCode'][0];
                    $this->asteriskCodes[$causeCode]($receive);
                }

                $this->eventLog($receive['headers']['Call-ID'][0], $receive['method'], $receive['headers']['CSeq'][0]);
                if ($receive['method'] == '200') {
                    $authorization = sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, "sip:{$to}@{$this->host}", "ACK");
                    $ackModel['headers']['Authorization'] = [$authorization];
                    $this->socket->sendto($this->host, $this->port, sip::renderSolution($ackModel));
                    break;
                }
            }
            if (!$this->headers200) $this->headers200 = $receive;


            if (!is_array($receive)) {
                // print cli::color('bold_red', "Chamada para $to não funcionará") . PHP_EOL;
                $this->receiveBye = true;
                $this->error = true;
                $this->bye();
                return false;
            }
            if (!array_key_exists('sdp', $receive)) {
                // print cli::color('bold_red', "Chamada para $to não funcionará") . PHP_EOL;
                $this->receiveBye = true;
                $this->error = true;
                $this->bye();
                return false;
            }
            $mediaIp = $receive['sdp']['c'][0];
            $mediaIp = explode(' ', $mediaIp)[2];
            $mediaPort = explode(' ', $receive['sdp']['m'][0])[1];
            $remoteAddressAudioDestination = explode(' ', $receive['sdp']['c'][0])[2];
            $remotePortAudioDestination = explode(' ', $receive['sdp']['m'][0])[1];
            $this->audioRemoteIp = network::resolveAddress($remoteAddressAudioDestination);
            $this->audioRemotePort = (int)$remotePortAudioDestination;
            $this->addListener($remoteAddressAudioDestination, $remotePortAudioDestination);


            //cli::pcl(sip::renderSolution($receive), 'green');


            $this->timeoutCall = time();
            $this->resetTimeout();
            // print cli::color('bold_green', "$mediaIp:$mediaPort receive") . PHP_EOL;
            if (is_callable($this->onAnswerCallback)) go(function ($param) {
                $closure = $param;
                $closure($this);
            }, $this->onAnswerCallback);


            for (; ;) {
                $res = $this->socket->recvfrom($peer, 0.01);
                if ($this->receiveBye) {
                    // print "Call ended 5 receiveBye" . PHP_EOL;
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
                $this->eventLog($receive['headers']['Call-ID'][0], $receive['method'], $receive['headers']['CSeq'][0]);
                if ($receive['method'] == 'CANCEL') $this->receiveBye = true;
                if ($receive['method'] == '503') $this->receiveBye = true;


                if ($receive['method'] == '202') {
                    //$this->receiveBye = true;
                    //$this->bye();
                    //return true;
                }
               // print $res . PHP_EOL;

                if (($receive['method'] == 'BYE') and ($receive['headers']['Call-ID'][0] == $this->callId)) {
                    $this->receiveBye = true;
                    if ($this->enableAudioRecording) $this->saveRtpBufferAsWav();
                    // print cli::color('bold_red', "receiveBye veio do outro lado") . PHP_EOL;
                    // print cli::color('bold_red', "CallID: {$receive['headers']['Call-ID'][0]} -> $this->callId") . PHP_EOL;
                    break;
                } else if ($this->receiveBye) {
                    // print "Call ended 6 receiveBye" . PHP_EOL;
                    return true;
                } else {
                    // print $receive['methodForParser'] . " - " . $receive['headers']['Call-ID'][0] . PHP_EOL;
                }
            }

            $this->receiveBye = true;
            // print "Call ended 7 Loop passed" . PHP_EOL;
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
        $re = $this->socket->recvfrom($peer, 5);
        if (!$re) {
            $this->registerCount++;
            return $this->register();
        }
        $receive = sip::parse($re);
        if (!array_key_exists('headers', $receive)) {
            // print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
        if (!array_key_exists('WWW-Authenticate', $receive['headers'])) {
            // print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
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
            $rec = $this->socket->recvfrom($peer, 0.01);
            if ($this->receiveBye) {
                // print "Call ended 1 receiveBye" . PHP_EOL;
                return true;
            }
            if (!$rec) {
                continue;
            } else {
                $receive = sip::parse($rec);
            }
            // print $receive['methodForParser'] . " - " . $receive['headers']['Call-ID'][0] . PHP_EOL;
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
            // print cli::color('bold_red', "Erro ao registrar telefone, tentando novamente...") . PHP_EOL;
            $this->registerCount++;
            return $this->register();
        }
    }

    public function modelInvite(string $to): array
    {
        $sdp = [
            'v' => ['0'],
            'o' => ["{$this->username} 0 0 IN IP4 {$this->localIp}"],
            's' => ['spechshop softphone'],
            'c' => ["IN IP4 {$this->localIp}"],
            't' => ['0 0'],
            'm' => ["audio {$this->audioReceivePort} RTP/AVP $this->codecMediaLine"],
            'a' => [
                ...$this->codecRtpMap,
            ],
        ];
        $sdp['a'][] = "ssrc:{$this->ssrc} cname:{$this->username}";

        return [
            "method" => "INVITE",
            "methodForParser" => "INVITE sip:{$to}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:" . $this->socket->getsockname()['port'] . ";branch=z9hG4bK-" . bin2hex(random_bytes(8))],
                "Max-Forwards" => ["70"],
                "From" => ["<sip:{$this->callerId}@{$this->host}>;tag=" . bin2hex(random_bytes(8))],
                "To" => ["<sip:{$to}@{$this->host}>"],
                "Allow" => ["INVITE,ACK,BYE,CANCEL,OPTIONS,NOTIFY,INFO,MESSAGE,UPDATE,REFER"],
                "Supported" => ["gruu,replaces,norefersub"],
                "User-Agent" => ["spechSoftPhone"],
                "Call-ID" => [$this->callId],
                "Contact" => [sip::renderURI(['user' => $this->username, 'peer' => [
                    'host' => $this->socket->getsockname()['address'],
                    'port' => $this->socket->getsockname()['port']
                ]])],
                "CSeq" => [$this->csq . " INVITE"],
                "Content-Type" => ["application/sdp"],
            ],
            "sdp" => $sdp
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

    public function eventLog($callId, $method, $csq)
    {
        return;
        if ($this->callId !== $callId) return// print cli::color('bold_red', "Evento infringido Call-ID: $callId -> $this->callId") . PHP_EOL;
            $this->sequenceCodes .= $method . '(' . $csq . ')' . ' ';
        // print $this->callId . " " . $this->sequenceCodes . PHP_EOL;
        // if ($method == '100')// print cli::color('bold_blue', "$callId recebeu método 100 Trying...") . PHP_EOL;
        // if ($method == '401')// print cli::color('bold_yellow', "$callId recebeu método 401 Unauthorized...") . PHP_EOL;
        // if ($method == '200')// print cli::color('bold_green', "$callId recebeu método 200 OK...") . PHP_EOL;
    }

    public function addListener(mixed $receiveIp, string $receivePort): void
    {
        $this->listeners[] = ['address' => $receiveIp, 'port' => $receivePort];
    }

    public function resetTimeout(): void
    {
        $this->timeoutCall = time();
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
        // print cli::color('bold_green', "Enviando áudio para $remoteIp:$remotePort") . PHP_EOL;
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
        $rtpSocket->bind($this->localIp, $this->audioReceivePort);
        $rtpSocket->setOption(SOL_SOCKET, SO_BROADCAST, 1);


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
                    // print cli::color('bold_green', "DTMF enviado: $dtmf") . PHP_EOL;
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
        $rtpSocket->close();
        return true;
    }

    private function generateDtmfPacket(int $dtmf, bool $endOfEvent = false, int $volume = 0x40, int $duration = 160): string
    {
        $payloadType = 101;
        static $timestamp = 0;
        $ssrc = 0x12345678;
        $rtpHeader = pack('CCnNN',
            0x80,
            $payloadType,
            $this->sequenceNumber++,
            $timestamp,
            $ssrc
        );

        // 160~320
        $timestamp += 160;
        $dtmfPayload = pack('CCCC',
            $dtmf,
            $volume,
            ($duration >> 8),
            ($duration & 0xFF) | ($endOfEvent ? 0x80 : 0x00)
        );
        return $rtpHeader . $dtmfPayload;
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

    public function startMedia(mixed $port): bool
    {
        return Coroutine::create(function () use ($port) {
            $this->lastTime = time();
            $socket = new Socket(AF_INET, SOCK_DGRAM, 0);


            if (!$socket->bind('0.0.0.0', $port)) {
                echo "Failed to bind to $port";
                return false;
            } else {
                // print cli::color('blue', "Proxy iniciado na porta $port") . PHP_EOL;
            }
            while (!$this->receiveBye) {
                $packet = $socket->recvfrom($peer, 0.1);
                if ($packet) {
                    $this->processRtpPacket($packet);
                }
            }
            return true;
        });
    }

    public function sendDtmf(string $dtmf): void
    {
        $this->dtmfList[] = $dtmf;
    }

    public function onAnswer($param): void
    {
        $this->onAnswerCallback = $param;
    }

    public function onVoice(callable $callback): void
    {
        $this->onVoiceCallback = $callback;
    }

    public function record(string $string): void
    {
        $this->enableAudioRecording = true;
        $this->recordAudioBuffer = '';
        $this->audioRecordingFile = $string;
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

    public function transferGroup(string $groupName, $retry = 0)
    {
        if ($retry > 3) {
            return false;
        }
        $retry++;

        $nameFile = \Extension\plugins\utils::baseDir() . '/groups.json';
        $groups = json_decode(file_get_contents($nameFile), true);
        if (!isset($groups[$groupName])) {
            echo "⚠ Grupo {$groupName} não encontrado.\n";
            return false;
        }

        $group = $groups[$groupName];
        $agents = $group['agents'];

        $connectionsFile = \Extension\plugins\utils::baseDir() . 'connections.json';
        $callsFile = \Extension\plugins\utils::baseDir() . 'calls.json';

        // Lista de agentes ocupados (já em chamadas)
        $excluded = [];
        $callsContent = json_decode(file_get_contents($callsFile), true);
        foreach ($callsContent as $callId => $data) {
            $excluded = array_merge($excluded, array_keys($data)); // IDs de agentes em call
        }

        $timeout = 35; // Tempo máximo para aguardar conexões
        $startTime = time();

        do {
            $connections = json_decode(@file_get_contents($connectionsFile), true);
            if (!is_array($connections)) {
                $connections = [];
            }

            // Filtra agentes:
            foreach ($agents as $idAgent => $agent) {
                // Remove se não está conectado
                if (!array_key_exists($agent, $connections)) {
                    unset($agents[$idAgent]);
                    continue;
                }
                // Remove se for o próprio usuário
                if ($agent === $this->username) {
                    unset($agents[$idAgent]);
                    continue;
                }
                // Remove se estiver em chamada ativa
                if (in_array($agent, $excluded)) {
                    unset($agents[$idAgent]);
                    continue;
                }
            }

            if (!empty($agents)) {
                break; // Encontrou agente livre e conectado
            }

            // Aguarda 1 segundo antes de tentar novamente
            usleep(1_000_000);

        } while ((time() - $startTime) < $timeout);

        if (empty($agents)) {
            $this->resetTimeout();
            $baseDir = \Extension\plugins\utils::baseDir();
            try {
                $this->declareAudio($baseDir . 'manage/ivr/espere.wav', 8, true);
            } catch (Exception $e) {
                echo $e->getMessage();
            }
            return $this->transferGroup($groupName, $retry);

        }

        // Faz a transferência para cada agente disponível
        foreach ($agents as $agent) {
            (function ($agent) {
                echo "✅ Transferindo chamada para {$agent}...\n";
                $this->transfer($agent);
            })($agent);
        }
    }


    public function declareAudio(string $string, mixed $currentCodec, $newSound = false): void
    {
        $filePath = $string;
        if ($newSound) $this->newSound = true;
        try {
            $trueFormat = phone::staticDetectFileFormatFromHeader($filePath);
        } catch (Exception $e) {
            cli::pcl("Erro ao detectar o formato do arquivo de áudio: $filePath", "red");
            $trueFormat = pathinfo($filePath, PATHINFO_EXTENSION);
        }
        if ($trueFormat !== pathinfo($filePath, PATHINFO_EXTENSION)) {
            $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.' . $trueFormat;
            rename($filePath, $newFilePath);
            $filePath = $newFilePath;
        }
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("Erro: Arquivo de áudio inválido ou não pode ser lido: $filePath");
        }
        $file = fopen($filePath, 'rb');
        $header = fread($file, 44);
        $sampleRate = unpack('V', substr($header, 24, 4))[1];
        $channels = unpack('v', substr($header, 22, 2))[1];
        $bitsPerSample = unpack('v', substr($header, 34, 2))[1];
        if ($bitsPerSample !== 16 || $channels !== 1 || $sampleRate !== 8000) {
            try {
                cli::pcl("Arquivo de áudio não suportado: $filePath - usando phone::staticConvertToWav", "red");
                $filePath = phone::staticConvertToWav($filePath, 8000, 1, 16);
            } catch (Exception $e) {
            }
        }
        if (!$file) {
            throw new Exception("Erro ao abrir o arquivo WAV: $filePath");
        }
        $header = fread($file, 1240);
        fclose($file);
        if (empty($header)) {
            print $header;
            sleep(1600);
            cli::pcl("Arquivo de áudio não suportado: $filePath - usando phone::staticConvertToWav", "white");
            try {
                $filePath = phone::staticConvertToWav($filePath);
            } catch (Exception $e) {
                cli::pcl("Erro ao converter o arquivo de áudio para WAV: $filePath", "red");
            }
        }


        cli::pcl("Novo audio declarado: $filePath");
        $this->audioFilePath = $filePath;
        $this->currentCodec = (int)$currentCodec;
        if ($this->currentCodec == 18) $this->channel = new bcg729Channel();
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

    private function detectSilence(string $payload): bool
    {
        $samples = str_split($payload, 2);
        $volume = 0;
        foreach ($samples as $sample) {
            $volume += abs(unpack('s', $sample)[1]);
        }
        // checa se ainda temos 3 segundos
        $len = strlen($volume);
        if ($len == 5) {
            $this->len = $len;
        }


        if ($this->lastTime + 3 < time()) {
            $this->lastTime = time();
            // print $this->len . " " . date('H:i:s') . "\n";
            $this->len = strlen($volume);
        }


        return $volume < 900;
    }

    private function detectVoicemail(?string $packet, float &$lastActivityTime, float $analysisStartTime, float $totalAnalysisTime = 2.5, int $silenceThreshold = 32768): bool
    {
        $currentTime = microtime(true);
        if ($currentTime - $analysisStartTime > $totalAnalysisTime) {
            echo "Tempo máximo de análise excedido.\n";
            return false;
        }
        if (empty($packet)) {
            if ($currentTime - $lastActivityTime > 2) {
                echo "Silêncio prolongado detectado. Possível caixa postal.\n";
                return true;
            }
            return false;
        }
        if (strlen($packet) < 12) {
            echo "Pacote RTP inválido ou corrompido detectado.\n";
            return false;
        }
        $payload = substr($packet, 12);
        if ($this->detectSilence2($payload, $silenceThreshold)) {
            if ($currentTime - $lastActivityTime > 2) {
                echo "Silêncio detectado. Possível caixa postal.\n";
                return true;
            }
        } else {
            $lastActivityTime = $currentTime;
        }

        return false;
    }

    private function detectSilence2(string $payload, int $threshold = 128): bool
    {
        $samples = str_split($payload, 2);
        $totalVolume = 0;
        foreach ($samples as $sample) {
            $totalVolume += abs(unpack('s', $sample)[1]);
        }
        $averageVolume = $totalVolume / count($samples);
        return $averageVolume < $threshold;
    }
}