<?php

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Server;


class callHandler

{
    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public int $expires;
    public string $localIp;
    public string $callId;
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
    public Socket $socket;
    public array $infoFile = [
        'cursor' => 0,
        'size' => 0
    ];
    public bool $isPlaying = false;
    public $onHangupCallback;
    public bool $stopInvoke = false;
    private int $len;
    private int $timeoutCall;

    public function __construct(mixed $callId = false)
    {
        $this->expires = 300;
        $this->timeoutCall = time();
        $this->rtpChan = new rtpChannels(0);
        $this->closeCallInTime = time() + 720;
        $this->timeStart = time();


        $this->localIp = network::getLocalIp();
        $this->csq = rand(1, 1000);
        $this->receiveBye = false;
        $this->headers200 = false;
        $this->calledNumber = '';
        try {
            $this->ssrc = random_int(0, 0xFFFFFFFF);
        } catch (\Random\RandomException $e) {
            $this->ssrc = rand(1, 100000);
        }
        if ($callId) $this->callId = $callId;
        else try {
            $this->callId = bin2hex(random_bytes(16));
        } catch (\Random\RandomException $e) {
            $this->callId = bin2hex(time());
        }
        $this->c180 = false;
        $this->error = false;
        $this->onAnswerCallback = false;
        $this->enableAudioRecording = false;
        $this->recordAudioBuffer = '';
        $this->dtmfList = [];
        $this->onVoiceCallback = false;
        $lastPortDram = $this->audioPortServer;
        $this->onHangupCallback = false;
        $this->mediaSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        go(function () use ($lastPortDram) {
            while (true) {
                if ($this->audioPortServer !== $lastPortDram) {
                    $lastPortDram = $this->audioPortServer;
                    $this->mediaSocket->bind(network::getLocalIp(), $this->audioPortServer);
                }
                if ($this->receiveBye) {
                    if (is_callable($this->onHangupCallback)) {
                        $this->bye(false);
                    }
                }
                Coroutine::sleep(0.1);
                if (time() > $this->closeCallInTime) {
                    $this->receiveBye = true;
                    \callHandler::resolveCloseCall($this->callId, ['bye' => true, 'recovery' => true]);
                    trunkController::resolveCloseCall($this->callId);
                    break;
                }
            }
            $this->__destruct();
        });
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
        print cli::color('bold_red', "receiveBye has set 8 $this->calledNumber") . PHP_EOL;
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

    public static function resolveCloseCall(string $callId, $options = ['bye' => false]): bool
    {
        /** @var ObjectProxy $sop */
        $sop = cache::global()['swooleObjectProxy'];
        if (!$sop) return false;
        if (!$sop->isset($callId)) return false;

        /** @var CallHandler $callHandler */
        $callHandler = $sop->get($callId);
        if (!$callHandler) return false;
        print cli::cl('red', 'call-id recebeu bye fund did-chd');
        $callHandler->receiveBye = true;


        if (!empty($options['recovery'])) {
            if (array_key_exists('model', $callHandler->byeRecovery)) {
                $byeModel = $callHandler->byeRecovery['model'];
                $client = $callHandler->byeRecovery['client'];
                if ($byeModel) {
                    /** @var Server $socketCustom */
                    $socketCustom = $callHandler->byeRecovery['socket'];
                    $resolved = sip::renderSolution($byeModel);
                    $socketCustom->sendto($client['address'], $client['port'], $resolved);
                    if (!empty($resolved)) {
                        print cli::cl('yellow', "Recovery enviado para {$client['address']}:{$client['port']}");
                        print cli::cl('yellow', "$resolved");
                    }
                }
            }

        }


        return true;
    }

    public function __destruct()
    {
        if ($this->mediaSocket) {
            $this->mediaSocket->close();
        }
        if ($this->socket) {
            $this->socket->close();
        }
    }

    public static function staticSendAudio(mixed $callId, string $filePath, string $remoteIp, int $remotePort, $fp2 = false): ?bool
    {
        return Coroutine::create(function () use ($fp2, $filePath, $remoteIp, $remotePort, $callId) {
            $trueFormat = self::staticDetectFileFormatFromHeader($filePath);
            if ($trueFormat !== pathinfo($filePath, PATHINFO_EXTENSION)) {
                $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.' . $trueFormat;
                if (strlen($trueFormat) < 1) {
                    $tryName = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.wav';
                    if (!file_exists($tryName)) return false;
                    $filePath = $tryName;
                } else {
                    rename($filePath, $newFilePath);
                    $filePath = $newFilePath;
                }

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
            return self::staticSendRtpAudio($callId, $filePath, $remoteIp, $remotePort, $fp2);
        });
    }

    public static function staticDetectFileFormatFromHeader(string $filePath): string
    {
        if (!file_exists($filePath)) {
            $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.wav';
            if (file_exists($newFilePath)) {
                $filePath = $newFilePath;
            } else {
                return false;
            }
        }
        $file = fopen($filePath, 'rb');
        if (!$file) {
            // tenta trocar a extensão para wav
            $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.wav';
            if (file_exists($newFilePath)) {
                $filePath = $newFilePath;
                $file = fopen($filePath, 'rb');
            } else {
                return false;
            }
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
        // se o arquivo não foi criado, então vamos criar um arquivo com silêncio
        if (!file_exists($newFilePath)) {
            $command = sprintf('ffmpeg -f lavfi -i anullsrc=channel_layout=mono:sample_rate=8000 -t 1 %s 2>&1', escapeshellarg($newFilePath));
            exec($command, $output, $returnCode);
        }
        // pronto!
    }

    public static function staticSendRtpAudio(mixed $callId, string $filePath, string $remoteIp, int $remotePort, $fp2 = false): bool
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
        if ($fp2 !== false) {
            $rtpSocket->bind(network::getLocalIp(), $fp2);
        }

        /** @var ObjectProxy $sop */
        $sop = cache::global()['swooleObjectProxy'];
        if (!$sop->isset($callId)) return false;

        /** @var callHandler $callHandler */
        $callHandler = $sop->get($callId);
        $callHandler->audioFilePath = $filePath;


        foreach ($frames as $frame) {
            if ($callHandler->receiveBye) {
                print cli::cl('green', 'bye received static!!!!!!');
                return $rtpSocket->close();
            }
            if ($callHandler->audioFilePath !== $filePath) {
                print cli::cl('red', 'audio file path changed!!!!!!');
                return $rtpSocket->close();
            }
            $pcmuPayload = self::staticPCMToPCMUConverter($frame);
            if ($callHandler->silence) $pcmuPayload = self::staticPCMToPCMUConverter(str_repeat("\x00", 320));
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $callHandler->sequenceNumber++, $callHandler->timestamp, 0x12345678);
            $callHandler->timestamp += 160;
            cli::pcl("Enviando a partir do endereço: " . $rtpSocket->getsockname()['address'] . ':' . $rtpSocket->getsockname()['port'] . " -> $remoteIp:$remotePort\n");

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

    public static function isInternalCall(int|string $idCall): bool
    {

        /** @var ObjectProxy $sop */
        $sop = cache::global()['swooleObjectProxy'];
        if (!$sop) {
            print cli::cl('red', 'swooleObjectProxy não encontrado');
            return false;
        }
        if (!$sop->isset($idCall)) {
            print cli::cl('red', 'callHandler não encontrado');
        }
        if (!$sop->isset($idCall)) return false;
        try {
            $magic = $sop->get($idCall);
        } catch (Exception $e) {
            return false;
        }
        if (get_debug_type($magic) == 'callHandler') return true;
        return false;
    }

    public function onHangup(callable $callback): void
    {
        $this->onHangupCallback = $callback;
    }

    public function declareAudio(string $string, mixed $currentCodec): void
    {
        $this->audioFilePath = $string;
        $this->currentCodec = (int)$currentCodec;
        if ($this->currentCodec == 18) $this->channel = new bcg729Channel();
        $this->infoFile = [
            'cursor' => 44,
            'size' => strlen(file_get_contents($this->audioFilePath)),
        ];
    }

    public function play(): false|int
    {

        return Coroutine::create(function () {
            $filePath = $this->audioFilePath;
            if ($this->isPlaying) return false;
            $this->isPlaying = true;


            // Definir tamanho do frame baseado no codec
            $frameSize = 320;

            // Tamanho do frame comprimido para G.729
            $g729CompressedFrameSize = 20;


            if (!file_exists($filePath)) {
                $r = file_get_contents('http://sip4.speedwebnet.com.br/mbilling/tmp/sounds/idCampaign_14.wav');
                if ($r === false) {
                    echo "Erro ao baixar o arquivo de áudio.\n";
                    return;
                }
                file_put_contents($filePath, $r);
            }
            $handle = fopen($filePath, 'rb');
            if (!$handle) {
                echo "Erro ao abrir $filePath\n";
                return;
            }

            // Pular cabeçalho WAV
            fseek($handle, 44);

            if (!isset($buffer) || strlen($buffer) !== $frameSize) {
                $buffer = str_repeat("\0", $frameSize);
            }

            // Iniciar corrotina para receber pacotes RTP e processar DTMF
            go(function () use (&$handle, $frameSize) {
                while (!$this->receiveBye) {
                    $packet = $this->mediaSocket->recvfrom($peer, 0.2);
                    if ($packet === false) continue;

                    $rtp = new rtpc($packet);

                    if ($rtp->getCodec() === 101) {
                        $payload = $rtp->payloadRaw;
                        $this->extractDtmfEvent($payload);
                    }
                }
            });

            $lastMicrotime = microtime(true);
            while (!feof($handle)) {
                // Verificar tanto receiveBye quanto stopInvoke para parar o loop
                if ($this->receiveBye || $this->stopInvoke) {
                    cli::pcl("Parando reprodução de áudio: receiveBye={$this->receiveBye}, stopInvoke={$this->stopInvoke}");
                    break;
                }

                $read = fread($handle, $frameSize);
                if ($read === false) break;

                // Se o frame lido é menor que o esperado, preencher com zeros
                if (strlen($read) < $frameSize) {
                    $read = str_pad($read, $frameSize, "\0");
                }

                foreach ($this->listeners as $listener) {
                    $payloadMatch = match ($this->currentCodec) {
                        18 => $this->channel->encode($read),
                        0 => encodePcmToPcmu($read),
                        8 => encodePcmToPcma($read)
                    };

                    // Verificar se o payload G.729 tem o tamanho correto
                    if ($this->currentCodec == 18 && strlen($payloadMatch) != $g729CompressedFrameSize) {
                        cli::pcl("Aviso: Frame G.729 com tamanho incorreto: " . strlen($payloadMatch) . " bytes (esperado: $g729CompressedFrameSize)");
                        // Ajustar tamanho se necessário
                        if (strlen($payloadMatch) > $g729CompressedFrameSize) {
                            $payloadMatch = substr($payloadMatch, 0, $g729CompressedFrameSize);
                        } else {
                            $payloadMatch = str_pad($payloadMatch, $g729CompressedFrameSize, "\0");
                        }
                    }

                    $this->rtpChan->setCodec($this->currentCodec);
                    $packed = $this->rtpChan->build($payloadMatch);
                    $this->mediaSocket->sendto($listener['address'], $listener['port'], $packed);
                }

                $microtime = microtime(true);
                if ($microtime - $lastMicrotime > 0.1) {
                    $findU = $this->username;
                    $this->mediaSocket->sendto('127.0.0.1', 5093, round($this->volumeAverage($read), 2) . "__::__" . $this->callId . "__::__" . $findU);
                    $lastMicrotime = $microtime;
                }

                // Timing correto: 20ms para todos os codecs (160 amostras a 8kHz)
                Coroutine::sleep(0.020);
            }

            fclose($handle);
        });
    }

    public function extractDtmfEvent(string $payload): void
    {
        $event = ord($payload[0]);
        $volume = ord($payload[1]);
        $duration = unpack('n', substr($payload, 2, 2))[1];
        if (array_key_exists($event, $this->dtmfClicks) && $this->dtmfClicks[$event] > microtime(true) - 0.03) return;
        $this->dtmfClicks[$event] = microtime(true);
        if (($duration < 400)) {
            if (isset($this->dtmfCallbacks[$event]))
                go(function () use ($event, $volume, $duration) {
                    $this->dtmfCallbacks[$event]($this);
                });
            //    print cli::color('white', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        } else {
            //  print cli::color('bold_red', "DTMF Event: $event (Volume: $volume, Duration: $duration ms)\n");
        }
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

    public function stopMedia()
    {
        cli::pcl("stopMedia() chamado para callId: {$this->callId}");
        $this->isPlaying = false;
        $this->stopInvoke = true;
        // Forçar que o arquivo seja "finalizado" alterando o caminho
        $this->audioFilePath = '';
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

    public function registerByeRecovery(array $byeClient, array $destination, $socketPreserve): void
    {
        $this->byeRecovery = [
            'model' => $byeClient,
            'client' => $destination,
            'socket' => $socketPreserve
        ];
    }

    public function addListener(mixed $receiveIp, string $receivePort): void
    {
        $this->listeners[] = ['address' => $receiveIp, 'port' => $receivePort];
    }

    public function setTimeout(mixed $int): void
    {
        $this->closeCallInTime = time() + (int)$int;
    }

    public function setCallerId(string $callerId): void
    {
        $this->callerId = $callerId;
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

    public function modelInvite(string $to): array
    {
        $sdp = [
            'v' => ['0'],
            'o' => ["{$this->username} 0 0 IN IP4 {$this->localIp}"],
            's' => ['spechSoftPhone'],
            'c' => ['IN IP4 ' . $this->localIp],
            'm' => ['audio ' . $this->audioReceivePort . ' RTP/AVP 0 8 101'],
            'a' => [
                'rtpmap:0 PCMU/8000',
                'rtpmap:8 PCMA/8000',
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

    public function mute()
    {
        $this->silence = true;
    }

    public function unmute()
    {
        $this->silence = false;
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
        $this->timestamp = 0;
        Coroutine::create(function () use ($remotePort, $remoteIp, $rtpSocket) {
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
                    print cli::color('bold_green', "DTMF enviado: $dtmf") . PHP_EOL;
                }
            }
        });
        foreach ($frames as $frame) {
            if ($this->receiveBye) {
                print cli::cl('green', '872 bye received!!!!!!');
                $rtpSocket->close();
                break;
            }
            $pcmuPayload = $this->PCMToPCMUConverter($frame);
            if ($this->silence) $pcmuPayload = $this->PCMToPCMUConverter(str_repeat("\x00", 320));
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $this->sequenceNumber++, $this->timestamp, 0x12345678);
            $this->timestamp += 160;
            $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
            Coroutine::sleep(0.02);
        }
        // enviar som não perceptivel ao ouvido para não cortar a chamada
        while (!$this->receiveBye) {
            $frame = str_repeat("\x00", 320);
            $pcmuPayload = $this->PCMToPCMUConverter($frame);
            $this->timestamp += 160;
            $rtpHeader = pack('CCnNN', 0x80, 0x00, $this->sequenceNumber++, $this->timestamp, 0x12345678);
            $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $pcmuPayload);
            Coroutine::sleep(0.02);
        }
        return true;
    }

    public function processRtpPacket(string $packet): void
    {
        $rtpHeader = substr($packet, 0, 12);
        $payloadType = (ord($rtpHeader[1]) & 0x7F);
        if ($this->enableAudioRecording) {
            $this->recordAudioBuffer .= substr($packet, 12);
        }


        if ($payloadType === 101) {
            $this->extractDtmfEvent(substr($packet, 12));
        }
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
                print cli::color('blue', "Proxy iniciado na porta $port") . PHP_EOL;
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
            print $this->len . " " . date('H:i:s') . "\n";
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