<?php

namespace libspech\Sip;

use Closure;
use libspech\Cache\rpcClient;
use libspech\Cli\cli;
use libspech\Network\network;
use libspech\Packet\renderMessages;
use libspech\Rtp\MediaChannel;
use libspech\Rtp\rtpc;
use libspech\Rtp\rtpChannel;
use Random\RandomException;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\Timer;

class trunkController
{
    public bool $callableRingInvoked = false;
    public mixed $username;
    public mixed $password;
    public mixed $host;
    public mixed $port;
    public \Swoole\Coroutine\Socket $socket;
    public int $expires;
    public string $localIp;
    public string $callId;
    public int $timestamp = 0;
    public int $audioReceivePort;
    public string $nonce = "";
    public bool $isRegistered = false;
    public int $csq;
    public int $ssrc;
    public bool $error = false;
    public bool $callActive = false;
    public int $timeoutCall;
    public string $callerId;
    public int $registerCount = 0;
    public int $socketPortListen = 0;
    public int $connectTimeout = 30;
    public array $headersNeedAuthorization = [
        "Proxy-Authenticate" => "Proxy-Authorization",
        "WWW-Authenticate" => "Authorization",
    ];
    public array $progressCodes = [
        100,
        180,
        181,
        182,
        183,
    ];
    public array $successCodes = [
        200,
        202,
        204,
    ];
    public array $failureCodes = [
        403,
        484,
        404,
        503,
        405,
        406,
        408,
        488,
        410,
        500,
        501,
        502,
        504,
        505,
        513,
        580,
        600,
        603,
        604,
        606,
    ];

    public string $bufferAudio = "";
    public mixed $sequenceNumber = 0;
    public array $dtmfList = [];
    public int $lastTime = 0;
    public bool $receiveBye = false;

    public $headers200;
    public string $calledNumber;
    public int $audioRemotePort = 0;
    public string $audioRemoteIp = "";
    public array $volumesAverage = [];
    public array $originVolumes = [];
    public bool $allowBuffer = false;
    public array $byeRecovery = [];
    public $onFailedCallback;
    public $onAnswerCallback;
    public ?string $fileRecord = null;
    public bool $speakWait = false;
    public int $speakWaitTime = 0;
    public array $speakWaitSequence = [];
    public int|float $lastSpeakTime = 0;
    public bool $blockSpeak = false;
    public int|float $speakTimeStart = 0;
    public bool $startSpeak = false;
    public int $totalSequence = 3;
    public array $socketsList = [];
    public bool $inTransfer = false;

    public mixed $currentMethod = null;
    public mixed $globalInfo = [];
    public array $dtmfCallbacks = [];
    public array $supportedCodecs = [
        0 => ["rtpmap:0 PCMU/8000"],
        18 => [
            "rtpmap:18 G729/8000",
            "fmtp:18 annexb=no",
        ],
        101 => [
            "rtpmap:101 telephone-event/8000",
            "fmtp:101 0-16",
        ],
        8 => ["rtpmap:8 PCMA/8000"],
    ];
    public array $members = [];
    public bool|string $domain = false;
    public array $ssrcSequences = [];
    public string $currentState = "";
    public string $codecMediaLine = "";
    public array $codecRtpMap = [];
    public array $box = [];
    public array $bufferWriteSound = [];
    public array $volumeCodec = [];
    public $rtpSocket;
    public $remoteIp;
    public $remotePort;
    public bool $newSound = false;
    public string $audioFilePath = '';
    public int $currentCodec = 8;
    public \bcg729Channel $channel;

    public array $listeners = [];
    public bool $enableAudioRecording = false;
    public string $recordAudioBuffer = '';
    public array $dtmfClicks = [];
    public $onHangupCallback;
    public int $closeCallInTime = 0;
    public $onRingingCallback;
    public $socketInUse;
    public $waitingEnd = 0;
    private Closure $audioFileHandle;
    public int $speakStartThreshold = 2;
    public int $speakEndThreshold = 3;
    public $prefix = '';
    public array $ptsRegistered = [];
    public array $ptsDtmfRegistered = [];
    public array $mapLearn = [];
    public $codecName;
    public $frequencyCall;
    public \Closure $onBuildAudio;
    public rtpChannel $rtpChannel;
    public array $inviteHeaders = [];
    private array $alawTable = [];
    private array $ulawTable = [];
    private bool $proxyMediaActive = false;
    private ?string $currentProxyId = null;
    private $userAgent;
    private string|int|null $ptTelephoneEvent;
    private string|int|null $ptUse;
    private array $sdp;
    public $bcgChannel;
    private bool $closing = false;
    private int $cid;
    private array $idTimers = [];


    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060, mixed $domain = false)
    {
        $this->onBuildAudio = fn($data) => $data;
        $this->bcgChannel = new \bcg729Channel();
        $this->username = $username ?? "";
        $this->callerId = $username ?? "";
        $this->password = $password ?? "";
        $this->domain = $domain ?? false;
        $this->socketInUse = false;
        $this->onHangupCallback = null;
        $this->onFailedCallback = null;
        $this->onAnswerCallback = null;
        $this->onRingingCallback = null;
        $this->cid = Coroutine::getCid();

        if (str_contains($host, "http")) {
            $caseUrl = parse_url($host);
        } else {
            $caseUrl = parse_url("http://{$host}");
        }
        $this->host = gethostbyname($caseUrl["host"]);
        if (empty($this->host) || $this->host === false) {
            throw new \Exception("Não foi possível resolver o host fornecido: {$host}");
        }
        $this->port = $port;
        $this->expires = 300;
        $this->timeoutCall = time();


        $this->calledNumber = "";

        $this->csq = rand(100, 99999);


        $this->ssrc = random_int(0, 0xffffffff);
        $this->callId = bin2hex(secure_random_bytes(8));
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->rtpSocket = new Socket(AF_INET, SOCK_DGRAM, 0);


        $this->audioReceivePort = network::getFreePort('udp');


        $this->rtpSocket->bind('0.0.0.0', $this->audioReceivePort);


        cli::pcl("Audio Receive Port: {$this->audioReceivePort}");
        $this->localIp = $this->socket->getsockname()["address"];


        $this->socket->bind($this->localIp, network::getFreePort('udp'));
        $this->socket->connect($this->host, $this->port);
        $this->socketPortListen = $this->socket->getsockname()["port"];


        $this->socketsList[] = $this->socket;
        $this->socketsList[] = $this->rtpSocket;


        $this->lastTime = time();
        $this->receiveBye = false;
        $this->error = false;
        $this->callActive = false;
        $this->members = [];


        // send options


        $this->socket->sendto($this->host, $this->port, sip::renderSolution($this->modelOptions()));
        $this->userAgent = 'SPECHSHOP LIB';

        /** @var ? $peer */
        print $this->socket->recvfrom($peer, 10);
        $this->mediaChannel = false;
        $this->registerAudioEvent(function () {
            // Evento de áudio registrado
        });


    }

    /**
     * Construir array OPTIONS para keepalive/ping do servidor SIP
     *
     * OPTIONS é usado para verificar conectividade e capacidades do servidor.
     * Geralmente enviado no constructor para testar conexão básica.
     *
     * Estrutura:
     * [
     *     "method" => "OPTIONS",
     *     "methodForParser" => "OPTIONS sip:host SIP/2.0",
     *     "headers" => [
     *         "Via" => [...],
     *         "From" => ["<sip:username@host>;tag=..."],
     *         "To" => ["<sip:host>"],
     *         "Call-ID" => ["..."],
     *         "CSeq" => ["N OPTIONS"],
     *         "Contact" => ["<sip:username@ip:port>"],
     *         "User-Agent" => ["SPECHSHOP LIB"],
     *         "Expires" => ["120"],
     *         ...
     *     ]
     * ]
     *
     * @return array Array de sinalização OPTIONS
     *
     * @see SIGNALING_ARRAYS.md para documentação completa
     */
    public function modelOptions(): array
    {
        return [
            "method" => "OPTIONS",
            "methodForParser" => "OPTIONS sip:{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=z9hG4bK-" . bin2hex(secure_random_bytes(4)) . ';rport'],
                "From" => ["<sip:{$this->username}@{$this->host}>"],
                "To" => ["<sip:{$this->host}>"],
                "Max-Forwards" => ["70"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " OPTIONS"],
                "User-Agent" => [$this->userAgent],
                "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE"],
                "Content-Length" => ["0"],
            ]
        ];
    }

    public static function extractVia(string $line): array
    {
        $result = [];

        // Divide o início do Via (protocolo e endereço) do resto
        if (preg_match('/^SIP\/2\.0\/(?P<transport>\w+)\s+(?P<host>[^;]+)/i', $line, $match)) {
            $result['transport'] = strtoupper($match['transport']);
            $hostParts = explode(':', $match['host']);
            $result['address'] = $hostParts[0];
            $result['port'] = isset($hostParts[1]) ? (int)$hostParts[1] : null;
        }

        // Extrai os parâmetros restantes (branch, rport, received, etc.)
        if (preg_match_all('/;\s*([^=;]+)=([^;]+)/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$_, $key, $value]) {
                $result[trim($key)] = trim($value);
            }
        }

        return $result;
    }

    public static function getWavDuration($file): string
    {
        if (!file_exists($file)) {
            return "Arquivo não encontrado";
        }
        $handle = fopen($file, "rb");
        if (!$handle) {
            return "Erro ao abrir o arquivo";
        }
        $header = fread($handle, 44);
        fclose($handle);
        if (strlen($header) < 44 || substr($header, 0, 4) != "RIFF" || substr($header, 8, 4) != "WAVE") {
            return "0:00:00";
        }
        $sampleRate = unpack("V", substr($header, 24, 4))[1];
        $numChannels = unpack("v", substr($header, 22, 2))[1];
        $bitDepth = unpack("v", substr($header, 34, 2))[1];
        $fileSize = filesize($file);
        $dataSize = $fileSize - 44;
        $samples = $dataSize / ($numChannels * ($bitDepth / 8));
        $durationInSeconds = $samples / $sampleRate;
        $durationInSeconds = round($durationInSeconds);
        return gmdate("H:i:s", $durationInSeconds);
    }

    public function mountLineCodecSDP(string $codec = 'PCMA/8000'): array
    {
        $codecRtpMap = [];
        $defaultRate = 8000;
        $defaultChannels = 1;

        $pt = null;
        $fmtp = [];
        $ptStrict = ['PCMU' => 0, 'PCMA' => 8, 'G729' => 18, 'telephone-event' => 101];
        $parts = explode('/', $codec);
        $name = $parts[0];
        if (!empty($parts[1])) {
            $defaultRate = $parts[1];
        }


        if (array_key_exists($name, $ptStrict)) {
            $pt = $ptStrict[$name];
        } else {
            if (strtoupper($name) === 'OPUS') $name = 'opus';
        }

        if ($pt === null) {
            $start = 97;
            for ($i = $start; $i < 128; $i++) {
                if (!array_key_exists($i, $this->ptsRegistered)) {

                    $this->ptsRegistered[$i] = $i;
                    $pt = $i;
                    break;

                }
            }
        }
        $lineString = "rtpmap:$pt $name/$defaultRate";


        if (!empty($parts[2])) {
            if (intval($parts[2]) > 1) $lineString .= "/$parts[2]";
        }
        $this->ptsRegistered[$pt] = $lineString;
        $start = 101;
        $ptDtmf = $start;
        for ($i = $start; $i < 128; $i++) {

            if (!array_key_exists($defaultRate, $this->ptsDtmfRegistered)) {
                if (!array_key_exists($i, $this->mapLearn)) {
                    $this->ptsDtmfRegistered[$defaultRate] = $i;
                    $ptDtmf = $i;
                    break;
                }

            }
        }
        $fmtp[] = "rtpmap:$ptDtmf telephone-event/" . $defaultRate;
        $fmtp[] = "fmtp:$ptDtmf 0-15";
        if ($pt == 18) {
            $fmtp[] = "fmtp:$pt annexb=no";
        }
        $this->mapLearn[$pt] = [$lineString];
        if (!array_key_exists($ptDtmf, $this->mapLearn)) $this->mapLearn[$ptDtmf] = $fmtp;


        return [
            $pt => [$lineString],
            $ptDtmf => $fmtp,
        ];
    }

    public function defineCodecs(array $codecs = [8, 101]): void
    {
        $codecMediaLine = "";
        $codecRtpMap = [];


        if (in_array(101, $codecs)) {
            $key = array_search(101, $codecs);
            unset($codecs[$key]);
            $codecs[] = 101;
        }
        foreach ($codecs as $codec) {
            if (array_key_exists($codec, $this->supportedCodecs)) {
                $codecMediaLine .= "{$codec} ";
                foreach ($this->supportedCodecs[$codec] as $line) {
                    $codecRtpMap[] = $line;
                }
            }
        }
        $this->codecMediaLine = trim($codecMediaLine);
        $this->codecRtpMap = $codecRtpMap;
        $this->codecRtpMap = array_unique($this->codecRtpMap);

        $newImplementation = self::codecsMapper($codecs);
        $this->codecMediaLine = $newImplementation["codecMediaLine"];
        $this->codecRtpMap = $newImplementation["codecRtpMap"];
    }

    public static function codecsMapper(array $codecs = ['PCMA', 'PCMU', 'RTP2833']): array
    {
        $ptMap = [
            'PCMU' => 0,
            'PCMA' => 8,
            'G729' => 18,
            'L16' => 96,
            'RTP2833' => 101,
            'telephone-event' => 101,
        ];

        $codecMediaLine = [];
        $codecRtpMap = [];

        foreach ($codecs as $codec) {
            if (is_numeric($codec)) {
                $codec = (int)$codec;
                $mode = 'reverse';
            } else {
                $mode = 'normal';
                $codec = strtoupper($codec);
            }
            if ($mode === 'reverse') {
                foreach ($ptMap as $value => $key) {
                    if ($key === $codec) {
                        $codec = $value;
                    }
                }
            }

            if (!isset($ptMap[$codec])) {
                continue;
            }

            $pt = $ptMap[$codec];

            switch ($codec) {
                case 'RTP2833':
                case 'TELEPHONE-EVENT':
                    $codecRtpMap[] = "rtpmap:{$pt} telephone-event/8000";
                    $codecRtpMap[] = "fmtp:{$pt} 0-15";
                    break;

                case 'L16':
                    $codecRtpMap[] = "rtpmap:{$pt} L16/8000";
                    break;
                case 'G729':
                    $codecRtpMap[] = "rtpmap:{$pt} G729/8000";
                    $codecRtpMap[] = "fmtp:18 annexb=no";
                    break;

                default:
                    $codecRtpMap[] = "rtpmap:{$pt} {$codec}/8000";
                    break;
            }
            $codecMediaLine[] = $pt;
        }

        return [
            'codecMediaLine' => implode(' ', $codecMediaLine),
            'codecRtpMap' => $codecRtpMap,
        ];
    }

    public function __invoke()
    {
        $callId = $this->callId;
        cli::pcl("CALL ID {$callId} foi criado");
    }

    public function removeMember(string $username): void
    {
        if (in_array($username, $this->members)) {
            $key = array_search($username, $this->members);
            unset($this->members[$key]);
        }
    }

    public function isMember(string $username): bool
    {
        return in_array($username, $this->members);
    }

    public function saveGlobalInfo(string $key, $value): void
    {
        $this->globalInfo[$key] = $value;
    }

    public function decodePcmuToPcm(string $input): string
    {
        if ($input === "") {
            return "";
        }
        if (empty($this->ulawTable)) {
            $this->initLawTables();
        }
        $pcm = "";
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $val = $this->ulawTable[ord($input[$i])];
            $pcm .= pack("v", $val);
        }
        return $pcm;
    }

    private function initLawTables(): void
    {
        if (!empty($this->alawTable)) {
            return;
        }
        for ($i = 0; $i < 256; $i++) {
            $a = $i ^ 0x55;
            $t = ($a & 0xf) << 4;
            $seg = ($a & 0x70) >> 4;
            if ($seg >= 1) {
                $t += 0x100;
                $t <<= $seg - 1;
            } else {
                $t += 8;
            }
            $this->alawTable[$i] = $a & 0x80 ? $t : -$t;
        }
        for ($i = 0; $i < 256; $i++) {
            $i2 = ~$i & 0xff;
            $t = (($i2 & 0xf) << 3) + 132;
            $t <<= ($i2 & 0x70) >> 4;
            $this->ulawTable[$i] = $i2 & 0x80 ? 132 - $t : $t - 132;
        }
    }

    public function proxyMedia(array $options): false|int
    {
        // Verificar se o proxy já está ativo para esta chamada
        if ($this->proxyMediaActive && $this->currentProxyId) {
            cli::pcl("ProxyMedia já está ativo para chamada {$this->callId} com ID {$this->currentProxyId}", 'yellow');
            return $this->currentProxyId;
        }

        return Coroutine::create(function () use ($options) {
            $spechId = $options['cid'] . "_{$options['peerIp']}:{$options['peerPort']}:{$options['proxyPort']}";
            $this->currentProxyId = $spechId;
            $this->proxyMediaActive = true;

            cli::pcl("Iniciando ProxyMedia para chamada {$this->callId} com ID {$spechId}", 'green');

            $portsUse = cache::global()["portsUse"] ?? [];

            $proxyPort = $options["proxyPort"];
            if (!$options['codecMapper']) {
                $this->proxyMediaActive = false;
                $this->currentProxyId = null;
                return false;
            }


            $fakeStartTime = time();
            $useFake = false;
            if (!$options['rcc']) {
                $useFake = true;
            }
            $portsUse[] = $proxyPort;
            $portsUse = array_values($portsUse);
            cache::define("portsUse", $portsUse);
            $callId = $this->callId;
            if ($this->inTransfer) {
                $kvc = array_keys($this->volumeCodec);
                foreach ($kvc as $ip) {
                    if (!array_key_exists($ip, $this->originVolumes)) {
                        $this->originVolumes[$ip] = $options["polo"];
                    }
                }
            }
            $apps = array_keys($this->originVolumes);


            foreach ($apps as $ip) {
                $username = $this->originVolumes[$ip];
                $this->box[$ip] = [
                    "pt" => $this->volumeCodec[$ip],
                    "username" => $username,
                    "channel" => false,
                    "rtp" => false,
                ];
            }
            $options["originVolumes"] = $this->originVolumes;
            $options["volumeCodec"] = $this->volumeCodec;
            $rpcClient = new rpcClient();
            $rpcClient->rpcSet($spechId, $options);

            return $callId;
        });
    }

    public function record(string $file): void
    {
        $this->allowBuffer = true;
        $this->fileRecord = $file;
    }

    public function onFailed(Closure $callback): void
    {
        $this->onFailedCallback = $callback;
    }

    public function onAnswer(callable $callback): void
    {
        $this->onAnswerCallback = $callback;
    }

    public function onRinging(Closure $param): void
    {
        $this->onRingingCallback = $param;
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
            $sample = unpack("s", substr($pcm, $i, 2))[1];
            $soma += $sample * $sample;
        }
        $rms = sqrt($soma / $numSamples);
        $normalized = $rms / $maxValue;
        return max(1, min(100, round($normalized * 100, 2)));
    }

    public function send2833($digits, int $durationMs = 200, int $volume = 10): void
    {
        if ($durationMs < 100) {
            $durationMs = 100;
        }
        if (!is_a($this->rtpSocket, Socket::class)) {
            return;
        }
        if ($this->closing || $this->error) {
            return;
        }


        foreach (str_split($digits, 1) as $digit) {
            try {
                $sequences = $this->rtpChannel->generateDtmfSequence($digit, $durationMs);
            } catch (\Throwable $e) {
                return;
            }
            foreach ($sequences as $sequence) {
                $this->rtpSocket->sendto($this->remoteIp, $this->remotePort, $sequence);
            }
            Coroutine::sleep($durationMs / 1000);
        }
    }

    public function call(string $to, $maxRings = 120): bool
    {

        $authSent = false;
        $level = 0;

        $modelInvite = $this->modelInvite($to, $this->prefix);
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelInvite));
        $timeRing = time();
        for (; ;) {
            if ($this->closing || $this->socket->isClosed()) {
                return false;
            }
            if (time() - $timeRing > $maxRings) {
                $this->error = true;
                if (is_callable($this->onFailedCallback)) {
                    return go($this->onFailedCallback, "O convite para a chamada não nenhum retorno após {$maxRings} segundos");
                }
            }
            if ($this->error) {
                return false;
            }
            /** @var ? $peer */
            $packet = $this->socket->recvfrom($peer, 2);


            if ($packet === false || $packet === "") {
                if ($this->socket->isClosed()) {
                    return false;
                }
                continue;
            }
            $receive = sip::parse($packet);
            $this->currentMethod = $receive["method"];
            if ($receive["method"] == "OPTIONS") {
                $this->socket->sendto($this->host, $this->port, renderMessages::respondOptions($receive["headers"]));
            }
            if (array_key_exists('sdp', $receive)) {
                $remoteAddressAudioDestination = explode(" ", $receive["sdp"]["c"][0])[2];
                $remotePortAudioDestination = explode(" ", $receive["sdp"]["m"][0])[1];
                $this->audioRemoteIp = $remoteAddressAudioDestination;
                $this->audioRemotePort = (int)$remotePortAudioDestination;
                if (array_key_exists('sdp', $receive) and !$this->callableRingInvoked) {
                    if ($receive['method'] > 180 && $receive['method'] < 200) {
                        if (is_callable($this->onRingingCallback)) {
                            go($this->onRingingCallback, $this);
                        }
                    }
                    if ($receive['method'] > 180 && $receive['method'] < 200) {
                        if (is_callable($this->onRingingCallback)) {
                            $this->onRingingCallback = null;
                        }
                    }
                    $this->callableRingInvoked = true;
                }
                $remoteAddressAudioDestination = explode(" ", $receive["sdp"]["c"][0])[2];
                $remotePortAudioDestination = explode(" ", $receive["sdp"]["m"][0])[1];
                $this->audioRemoteIp = $remoteAddressAudioDestination;
                $this->audioRemotePort = (int)$remotePortAudioDestination;
                //$this->receiveMedia();
            }
            if (!array_key_exists("Call-ID", $receive["headers"])) {
                if (array_key_exists("i", $receive["headers"])) {
                    $receive["headers"]["Call-ID"] = [$receive["headers"]["i"][0]];
                }
            }
            if ($receive["headers"]["Call-ID"][0] !== $this->callId) {
                continue;
            }
            $needAuth = $this->checkAuthHeaders($receive["headers"]);
            if ($needAuth && !$authSent) {
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
                        $nonce = $this->nonce;
                    }
                    if (str_contains($valueHeader, 'qop="')) {
                        $qop = value($valueHeader, 'qop="', '"');
                    } else {
                        $qop = "auth";
                    }
                    $modelInvite["headers"][$needAuth] = [sip::generateResponseProxy($this->username, $this->password, $realm, $nonce, sprintf("sip:%s@%s", $to, $this->host), "INVITE", $qop)];
                }
                if ($needAuth == "Authorization") {
                    $wwwAuthenticate = $receive["headers"]["WWW-Authenticate"][0];
                    $nonce = value($wwwAuthenticate, 'nonce="', '"');
                    $realm = value($wwwAuthenticate, 'realm="', '"');
                    $modelInvite["headers"][$needAuth] = [sip::generateAuthorizationHeader($this->username, $realm, $this->password, $nonce, sprintf("sip:%s@%s", $to, $this->localIp), "INVITE")];
                }
                $this->csq++;
                $this->ssrc = random_int(0, 0xffffffff);
                $modelInvite["headers"]["CSeq"] = [sprintf("%d INVITE", $this->csq)];
                $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelInvite));
                $authSent = true;
                continue;
            }
            if (in_array($receive["method"], $this->progressCodes)) {
                $level++;
            }
            if (in_array($receive["method"], $this->successCodes)) {
                if (array_key_exists('sdp', $receive)) {
                    break;
                }
            }
            if (in_array($receive["method"], $this->failureCodes)) {
                break;
            }
        }
        if (!is_array($receive)) {
            $this->error = true;
            print "Falhou pois não recebeu resposta depois do INVITE" . PHP_EOL;
            if (is_callable($this->onFailedCallback)) {
                return go($this->onFailedCallback, $receive['methodForParser']);
            }
        }
        if (!is_array($receive)) {
            $this->error = true;
            if (is_callable($this->onFailedCallback)) {
                return go($this->onFailedCallback, $receive['methodForParser']);
            }
        }
        if (!array_key_exists("headers", $receive)) {
            $this->error = true;
            if (is_callable($this->onFailedCallback)) {
                return go($this->onFailedCallback, $receive['methodForParser']);
            }
        }
        if (!array_key_exists("sdp", $receive)) {
            $this->error = true;
            if (is_callable($this->onFailedCallback)) {
                return go($this->onFailedCallback, $receive['methodForParser']);
            }
        }
        if (in_array($receive['method'], $this->failureCodes)) {
            $this->error = true;
            if (is_callable($this->onFailedCallback)) {
                return go($this->onFailedCallback, $receive['methodForParser']);
            } else {
                return false;
            }
        }
        $this->callActive = true;
        $this->headers200 = $receive;
        $ackModel = $this->ackModel($receive["headers"]);
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($ackModel));
        $remoteAddressAudioDestination = explode(" ", $receive["sdp"]["c"][0])[2];
        $remotePortAudioDestination = explode(" ", $receive["sdp"]["m"][0])[1];
        $this->audioRemoteIp = $remoteAddressAudioDestination;
        $this->audioRemotePort = (int)$remotePortAudioDestination;


        if (is_callable($this->onAnswerCallback)) {
            go($this->onAnswerCallback, $this);
        }
        for (; ;) {
            if ($this->closing || $this->receiveBye) {
                return false;
            }
            if ($this->error) {
                if (is_callable($this->onHangupCallback)) {
                    go($this->onHangupCallback, $this);
                }
                return false;
            }
            if ($this->receiveBye) {
                if (is_callable($this->onHangupCallback)) {
                    go($this->onHangupCallback, $this);
                }
                return false;
            }

            // Verificar se o socket foi fechado
            if ($this->socket->isClosed()) {
                return false;
            }

            $res = $this->socket->recvfrom($peer, 1);
            if (!$res) {
                if ($this->socket->isClosed()) {
                    return false;
                }
                continue;
            } else {
                $receive = sip::parse($res);

                if ($receive["method"] == "NOTIFY") {
                    $this->callActive = false;
                    $this->receiveBye = true;
                    $this->unblockCoroutine();
                    return false;
                }
                if ($receive["method"] == "BYE") {
                    $this->receiveBye = true;
                    $this->callActive = false;
                    $this->unblockCoroutine();
                    if (is_callable($this->onHangupCallback)) {
                        return go($this->onHangupCallback, $this, $receive, $peer);
                    }
                }
            }
            if (!array_key_exists("Call-ID", $receive["headers"])) {
                if (array_key_exists("i", $receive["headers"])) {
                    $receive["headers"]["Call-ID"] = [$receive["headers"]["i"][0]];
                }
            }
            if ($receive["headers"]["Call-ID"][0] !== $this->callId) {
                continue;
            }
            if ($receive["method"] == "NOTIFY") {
                $this->callActive = false;
                $this->receiveBye = true;
                $this->unblockCoroutine();
                return false;
            }
            if ($receive["method"] == "BYE") {
                $this->receiveBye = true;
                $this->callActive = false;
                $this->unblockCoroutine();
                if (is_callable($this->onHangupCallback)) {

                    return go($this->onHangupCallback, $this, $receive, $peer);
                }
            } elseif ($this->receiveBye) {
                print "Call ended 6 receiveBye" . PHP_EOL;
                return true;
            } else {
                print $receive["methodForParser"] . " - " . $receive["headers"]["Call-ID"][0] . PHP_EOL;
                var_dump($this->socket->isClosed());
                sleep(1);
                if ($receive['method'] == 'NOTIFY') {
                    $this->receiveBye = true;
                    if (is_callable($this->onHangupCallback)) {
                        go($this->onHangupCallback, $this);
                    }
                    return false;
                }
            }
        }
        $this->receiveBye = true;
        if (is_callable($this->onHangupCallback)) {
            go($this->onHangupCallback, $this);
        }
        print "Call ended 7 Loop passed" . PHP_EOL;
        return true;

    }

    public function modelInvite(string $to, $prefix = "", $options = []): array
    {
        $this->calledNumber = $to;
        if (!$this->username) {
            if ($this->callerId) {
                $this->username = $this->callerId;
            } else {
                $this->username = "100";
            }
        }
        $this->codecRtpMap = [];
        $codecs = array_keys($this->mapLearn);
        foreach ($codecs as $codec) {
            foreach ($this->mapLearn[$codec] as $media) {
                $this->codecRtpMap[] = $media;
            }
        }
        //var_dump( $this->mapLearn);


        $sdp = [
            "v" => ["0"],
            "o" => ["{$this->ssrc} 0 0 IN IP4 {$this->localIp}"],
            "s" => [$this->userAgent],
            "c" => ["IN IP4 {$this->localIp}"],
            "t" => ["0 0"],
            "m" => ["audio {$this->rtpSocket->getsockname()['port']} RTP/AVP " . implode(' ', array_keys($this->mapLearn))],
            "a" => [
                'ssrc:' . $this->ssrc . ' cname:' . (!empty($this->callerId) ? $this->callerId : $this->username) . "@{$this->localIp}",
                ...$this->codecRtpMap,
                'ptime:20',
                'sendrecv',
            ],
        ];
        $this->sdp = $sdp;
        cli::pcl("audio {$this->rtpSocket->getsockname()['port']} RTP/AVP " . implode(' ', array_keys($this->mapLearn)), 'bold_green');
        $this->ptUse = array_key_first($this->mapLearn);
        $this->ptTelephoneEvent = array_key_last($this->mapLearn);
        $this->codecName = self::getSDPModelCodecs($this->sdp['a'])['preferredCodec']['name'];
        $this->frequencyCall = self::getSDPModelCodecs($this->sdp['a'])['preferredCodec']['rate'];
        if ($this->domain) {
            $mf = $this->domain;
        } else {
            $mf = $this->host;
        }
        if ($this->port != 5060) {
            $mf .= ":" . $this->port;
        }
        $toCall = [
            'user' => ($prefix ?? '') . $to,
            'peer' => [
                'host' => $this->host ?? $this->domain,
                'port' => $this->port ?? 5060,
            ]
        ];


        if (strlen($prefix) > 0) {
            $to = $prefix . $to;
        }
        $this->calledNumber = $to;
        $settings = [
            "method" => "INVITE",
            "methodForParser" => "INVITE sip:{$to}@{$mf} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=z9hG4bK64d" .
                    bin2hex(secure_random_bytes(8) ?? time()) .
                    ";rport"
                ],

                "From" => [sip::renderURI([
                    "user" => !empty($this->callerId) ? $this->callerId : $this->username,
                    "peer" => [
                        "host" => $this->host ?? $this->domain,
                        "port" => $this->port,
                    ],
                    "additional" => ["tag" => bin2hex(secure_random_bytes(10))],
                ])],
                "To" => [sip::renderURI($toCall)],
                "Supported" => ["gruu,replaces"],
                "User-Agent" => [$this->userAgent],
                "Call-ID" => [$this->callId],
                "Allow" => ["INVITE,ACK,BYE,CANCEL,OPTIONS,NOTIFY,MESSAGE,REFER"],
                "Contact" => ["<sip:{$this->username}@{$this->localIp}:{$this->socketPortListen}>"],
                "CSeq" => [$this->csq . " INVITE"],
                "Max-Forwards" => ["70"],
                "Content-Type" => ["application/sdp"],
                "Date" => [date("D, d M Y H:i:s T")],
                "P-Preferred-Identity" => ['"' . $this->callerId . '" ' . sip::renderURI([
                        'user' => !empty($this->callerId) ? $this->callerId : $this->username,
                        'peer' => [
                            'host' => $this->localIp,
                            'port' => $this->socketPortListen,
                        ],
                    ])],

            ],
            "sdp" => $sdp,
        ];

        $this->inviteHeaders = $settings;

        return $settings;
    }

    public static function getSDPModelCodecs(array $sdpAttributes): array
    {
        $codecMediaLine = "";
        $codecRtpMap = [];
        $preferredCodec = null;
        $dtmfCodec = null;
        $lineArg = [];
        $rate = 8000;

        // Primeiro, encontrar o codec principal (não telephone-event)
        foreach ($sdpAttributes as $row) {
            if (str_starts_with($row, "rtpmap:")) {
                $pt = value($row, "rtpmap:", " ");
                $rate = explode('/', $row)[1];
                $name = value($row, ' ', '/');
                $codecMediaLine .= "{$pt} ";
                $codecRtpMap[] = $row;

                if ($name !== 'telephone-event') {
                    $preferredCodec = [
                        'pt' => $pt,
                        'rate' => $rate,
                        'sdp' => $row,
                        'name' => $name
                    ];
                    break; // Encerrar quando encontrar o codec preferencial
                }
            }
        }

        // Adicionar fmtp após o codec principal encontrado
        foreach ($sdpAttributes as $row) {
            if (str_starts_with($row, "fmtp:")) {
                $fmtpPt = value($row, "fmtp:", " ");
                if ($preferredCodec && $fmtpPt === $preferredCodec['pt']) {
                    $codecRtpMap[] = $row; // Inclui o fmtp correspondente
                    $lineArg = self::parseArgumentRtpMap($row);
                }
            }
        }

        // Depois, encontrar o codec `telephone-event` com o mesmo rate
        foreach ($sdpAttributes as $row) {
            if (str_starts_with($row, "rtpmap:") && str_contains($row, 'telephone-event')) {
                $dtmfPt = value($row, "rtpmap:", " ");
                $dtmfRate = explode('/', $row)[1];

                // Verifica se o rate do DTMF é o mesmo do codec preferencial
                if ($dtmfRate == $rate) {
                    $codecMediaLine .= "{$dtmfPt} ";
                    $codecRtpMap[] = $row;
                    $dtmfCodec = [
                        'pt' => $dtmfPt,
                        'rate' => $dtmfRate,
                        'sdp' => $row,
                        'name' => 'telephone-event'
                    ];
                    break; // Encerrar no primeiro DTMF correspondente
                }
            }
        }
        if (!$dtmfCodec) {
            // confere pela ultima vez entao se realmente nao tem
            foreach ($sdpAttributes as $row) {
                if (str_starts_with($row, "rtpmap:") && str_contains($row, 'telephone-event')) {
                    $dtmfPt = value($row, "rtpmap:", " ");
                    $dtmfRate = explode('/', $row)[1];
                    $codecMediaLine .= "{$dtmfPt} ";
                    $codecRtpMap[] = $row;
                    $dtmfCodec = [
                        'pt' => $dtmfPt,
                        'rate' => $dtmfRate,
                        'sdp' => $row,
                        'name' => 'telephone-event'
                    ];
                    break; // Encerrar no primeiro DTMF correspondente
                }
            }
        }


        // Adicionar fmtp após o codec DTMF encontrado
        foreach ($sdpAttributes as $row) {
            if (str_starts_with($row, "fmtp:")) {
                $fmtpPt = value($row, "fmtp:", " ");


                if ($dtmfCodec && $fmtpPt === $dtmfCodec['pt']) {
                    $codecRtpMap[] = $row; // Inclui o fmtp correspondente
                }
            }
        }


        return [
            "codecMediaLine" => trim($codecMediaLine),
            "codecRtpMap" => $codecRtpMap,
            "preferredCodec" => $preferredCodec,
            "dtmfCodec" => $dtmfCodec,
            "config" => [
                (int)$preferredCodec['pt'] => $lineArg
            ]
        ];
    }

    public static function parseArgumentRtpMap(string $line): array
    {
        if (!str_contains($line, 'fmtp')) return [];
        $result = [];
        $parts = explode(' ', $line)[1];
        $matches = []; //maxplaybackrate=24000;sprop-maxcapturerate=24000;maxaveragebitrate=64000;useinbandfec=1
        preg_match_all('/(\w+)=(\d+)/', $parts, $matches);
        foreach ($matches[1] as $key => $value) {
            $result[$value] = $matches[2][$key];
        }
        return $result;
    }

    /**
     * @throws RandomException
     */


    public static function renderURI(array $uriData): string
    {
        $user = $uriData["user"] ?? "";
        $peer = $uriData["peer"] ?? [];
        $additional = $uriData["additional"] ?? [];
        $host = $peer["host"] ?? "";
        $port = $peer["port"] ?? "";
        $extra = $peer["extra"] ?? "";
        $uri = "<sip:{$user}@{$host}";
        if (!empty($port) and $port != "5060") {
            $uri .= ":{$port}";
        }
        if (!empty($extra)) {
            $uri .= ";{$extra}";
        }
        $uri .= ">";
        if (!empty($additional)) {
            $additionalParams = [];
            foreach ($additional as $key => $value) {
                $additionalParams[] = "{$key}={$value}";
            }
            $uri .= ";" . implode(";", $additionalParams);
        }
        return $uri;
    }

    public function checkAuthHeaders(array $headers)
    {
        foreach ($this->headersNeedAuthorization as $header => $value) {
            if (array_key_exists($header, $headers)) {
                return $value;
            }
        }
        return false;
    }

    /**
     * Construir array ACK para confirmar recepção de 200 OK
     *
     * O ACK é enviado em resposta a uma resposta 2xx bem-sucedida.
     *
     * Estrutura:
     * [
     *     "method" => "ACK",
     *     "methodForParser" => "ACK sip:contact@host:port SIP/2.0",
     *     "headers" => [
     *         "Via" => [...com novo branch...],
     *         "From" => [...idêntico ao INVITE...];tag=..."],
     *         "To" => [...idêntico ao INVITE...];tag=server-tag"],
     *         "Call-ID" => [...idêntico...],
     *         "CSeq" => ["N ACK"]
     *     ]
     * ]
     *
     * Características importantes:
     * - Call-ID, From, To DEVEM ser idênticos ao INVITE
     * - CSeq usa mesmo número do INVITE, mas método muda para "ACK"
     * - ACK NÃO espera resposta (nenhuma resposta para ACK é válida)
     * - Sem corpo/SDP no ACK
     *
     * @param array $headers Headers da resposta 200 OK (extraidos com sip::parse())
     *
     * @return array Array de sinalização ACK pronto para envio
     *
     * @example
     * // Após receber 200 OK
     * $received = sip::parse($response);
     * $modelAck = $phone->ackModel($received['headers']);
     * $phone->socket->sendto($host, $port, sip::renderSolution($modelAck));
     *
     * @see SIGNALING_ARRAYS.md para documentação completa
     */
    public function ackModel(array $headers): array
    {
        $ruleNeed = [
            "Contact",
            "CSeq",
            "From",
            "To",
            "Call-ID",
        ];
        foreach ($ruleNeed as $rule) {
            if (!array_key_exists($rule, $headers)) {
                return [];
            }
        }
        $contactUri = trunkController::extractURI($headers["Contact"][0]);
        $ackInt = explode(" ", $headers["CSeq"][0])[0];
        $this->csq = $ackInt;
        $uriFrom = trunkController::extractURI($headers["From"][0]);
        $uriTo = trunkController::extractURI($headers["To"][0]);
        $base = [
            "method" => "ACK",
            //"methodForParser" => "ACK sip:{$uriFrom["user"]}@{$contactUri["peer"]["host"]}:{$contactUri["peer"]["port"]} SIP/2.0",
            "methodForParser" => "ACK sip:{$contactUri["user"]}@{$contactUri["peer"]["host"]}:{$contactUri["peer"]["port"]} SIP/2.0",


            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=" . bin2hex(secure_random_bytes(8))],
                "Max-Forwards" => ["70"],
                "From" => [trunkController::renderURI([
                    "user" => $uriFrom["user"],
                    "peer" => [
                        "host" => $uriFrom["peer"]["host"],
                        "port" => $uriFrom["peer"]["port"],
                    ],
                    "additional" => ["tag" => $uriFrom["additional"]["tag"] ?? ""],
                ])],
                "To" => [trunkController::renderURI([
                    "user" => $uriTo["user"],
                    "peer" => [
                        "host" => $uriTo["peer"]["host"],
                        "port" => $uriTo["peer"]["port"],
                    ],
                    "additional" => ["tag" => $uriTo["additional"]["tag"] ?? ""],
                ])],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " ACK"],
            ],
        ];
        if (array_key_exists("Record-Route", $headers)) {
            $base["headers"]["Route"] = $headers["Record-Route"];
        }
        return $base;
    }

    public static function extractURI($line): array
    {
        if (!str_contains($line, 'sip:')) {
            return [];
        }
        $user = value($line, 'sip:', '@');
        $peerFirst = value($line, $user . '@', '>');
        $peerParts = explode(';', $peerFirst, 2);
        $hostPort = explode(':', $peerParts[0], 2);
        $additionalParams = [];
        if (str_contains($line, '>')) {
            $remaining = substr($line, strpos($line, '>') + 1);
            parse_str(str_replace(';', '&', $remaining), $additionalParams);
        }
        if (str_contains($user, ':')) {
            $user = str_replace('>', '', $user);
            $hostPort = explode(':', $user, 2);
            $user = '';
        }
        return [
            'user' => $user,
            'peer' => [
                'host' => $hostPort[0],
                'port' => $hostPort[1] ?? '5060',
                'extra' => $peerParts[1] ?? '',
            ],
            'additional' => $additionalParams,
        ];
    }

    public function unblockCoroutine(): bool
    {
        return $this->receiveBye = false;
    }

    public bool|MediaChannel $mediaChannel;


    public function receiveMedia(): void
    {

        Coroutine::create(function () {
            if ($this->socketInUse !== false) return false;


            $this->socketInUse = 'yes';


            $this->remoteIp = $this->audioRemoteIp;
            $this->remotePort = $this->audioRemotePort;


            $rtpSocket = $this->rtpSocket;
            $rtpSocket->bind($this->localIp, $this->audioReceivePort);
            $rtpSocket->connect($this->remoteIp, $this->remotePort);
            cli::pcl("Proxy de áudio iniciado na porta " . $this->localIp . ":" . $rtpSocket->getsockname()['port']);
            $this->lastSpeakTime = microtime(true);
            $this->speakWaitSequence = [];
            $this->waitingEnd = 0;
            $this->startSpeak = false;
            $silPayload20ms = str_repeat("\x00", 160);


            $audioFile = null;
            $audioData = null;
            $audioPosition = 0;
            $audioFinished = false;

            $this->error = false;
            $this->callActive = true;
            $this->receiveBye = false;
            $audioFile = $this->audioFilePath;


            $this->mediaChannel = new MediaChannel($rtpSocket, $this->callId);
            $this->mediaChannel->portList = $this->audioReceivePort;
            $this->mediaChannel->onDtmfCallable = $this->onDtmfCallable;


            $this->mediaChannel->codecMapper = [
                $this->ptUse => strtoupper(implode('/', [
                    $this->codecName,
                    $this->frequencyCall,
                ])),
            ];
            $this->mediaChannel->registerPtCodecs($this->mediaChannel->codecMapper);


            $this->mediaChannel->addMember([
                'address' => $this->audioRemoteIp,
                'port' => $this->audioRemotePort,
                'codec' => $this->codecName,
                'pt' => $this->ptUse,
                'timestamp' => time(),
                'config' => [],
                'ssrc' => $this->ssrc,
                'frequency' => $this->frequencyCall,
            ]);


            $this->rtpChannel = new RtpChannel($this->ptUse, $this->frequencyCall, 20, $this->ssrc);
            $this->mediaChannel->onReceive(function (rtpc $rtpc, array $peer, MediaChannel $channel, rtpChannel $rtpChannel) use ($rtpSocket, $silPayload20ms) {
                $targetId = $peer['address'] . ':' . $peer['port'];
                $ssrc = $rtpc->ssrc;
                if (!array_key_exists($ssrc, $channel->rtpChans)) {
                    $channel->rtpChans[$ssrc] = $this->rtpChannel;
                }
                $codec = $this->codecName;

                $pcmData = match (strtoupper($codec)) {
                    'G729' => $channel->rtpChans[$ssrc]->bcg729Channel->decode($rtpc->payloadRaw),
                    'PCMU' => decodePcmuToPcm($rtpc->payloadRaw),
                    'PCMA' => decodePcmaToPcm($rtpc->payloadRaw),
                    'OPUS' => $channel->members[$targetId]['opus']->decode($rtpc->payloadRaw),
                    'L16' => pcmLeToBe($rtpc->payloadRaw),
                    default => $rtpc->payloadRaw,
                };
                if (!is_callable($this->onReceivePcmCallback)) {
                    $this->onReceivePcmCallback = function ($pcm, $peer, $context) use ($silPayload20ms) {
                    };
                }
                try {
                    go($this->onReceivePcmCallback, $pcmData, $peer, $this);
                } catch (\Exception $e) {
                    var_dump($e);
                    exit;
                }


                if (is_callable($this->audioFileHandle)) {
                    $closure = ($this->audioFileHandle)(...);
                    go($closure, $pcmData, $peer, $this);
                }
            });

            $this->mediaChannel->start();

            // Garantir que o mediaChannel seja desbloqueado e fechado
            $this->mediaChannel?->unblock();
        });
    }


    public ?Closure $onDtmfCallable;

    public function onKeyPress(callable $callback): void
    {
        $this->onDtmfCallable = $callback;
    }

    public function addMember(string $username): void
    {
        if (!in_array($username, $this->members)) {
            $this->members[] = $username;
        }
    }

    public function onBeforeAudioBuild(Closure $closure): void
    {
        $this->onBuildAudio = $closure;
    }

    /**
     * Construir array CANCEL para cancelar chamada pendente
     *
     * CANCEL é usado para terminar uma chamada que ainda está em fase de setup
     * (quando toca mas o destino ainda não respondeu com 200 OK).
     *
     * Estrutura:
     * [
     *     "method" => "CANCEL",
     *     "methodForParser" => "CANCEL sip:called@host SIP/2.0",
     *     "headers" => [
     *         "Via" => [...novo branch...],
     *         "From" => [...],
     *         "To" => [...],
     *         "Call-ID" => [...idêntico...],
     *         "CSeq" => ["N CANCEL"]  // Mesmo número do INVITE
     *     ]
     * ]
     *
     * Características:
     * - CSeq usa MESMO número do INVITE, mas método muda para "CANCEL"
     * - Call-ID deve ser idêntico ao INVITE original
     * - Sem corpo/SDP
     * - Servidor responde com 200 OK, depois envia 487 Request Terminated ao destino
     *
     * @param bool|string $called Número chamado (opcional, usa this->calledNumber)
     *
     * @return array Array de sinalização CANCEL pronto para envio
     *
     * @example
     * // Para cancelar uma chamada em andamento
     * $modelCancel = $phone->getModelCancel();
     * $phone->socket->sendto($host, $port, sip::renderSolution($modelCancel));
     *
     * @see SIGNALING_ARRAYS.md para documentação completa
     */
    public function getModelCancel($called = false): array
    {
        if ($called) {
            $this->calledNumber = $called;
        }
        return [
            "method" => "CANCEL",
            "methodForParser" => "CANCEL sip:{$this->calledNumber}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socket->getsockname()["port"]};branch=z9hG4bK-" . bin2hex(secure_random_bytes(4))],
                "From" => ["<sip:{$this->username}@{$this->host}>;tag=" . bin2hex(secure_random_bytes(8))],
                "Max-Forwards" => ["70"],
                "User-Agent" => ["{$this->userAgent}"],
                "Contact" => [sip::renderURI([
                    "user" => $this->username,
                    "peer" => [
                        "host" => $this->socket->getsockname()["address"],
                        "port" => $this->socket->getsockname()["port"],
                    ],
                ])],
                "To" => ["<sip:{$this->calledNumber}@{$this->host}>"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " CANCEL"],
            ],
        ];
    }

    public function close(): void
    {

        // Evitar múltiplas chamadas
        if ($this->closing) {
            return;
        }
        foreach ($this->idTimers as $id => $timer) {
            Timer::clear($id);
        }


        cli::pcl("Iniciando fechamento Call-ID: {$this->callId}", 'yellow');

        // Marca que está fechando ANTES de tudo para interromper loops
        $this->closing = true;
        $this->error = true;
        $this->receiveBye = true;
        $this->callActive = false;
        $this->blockSpeak = false;


        // Envia BYE se houver chamada ativa (fazer antes de fechar sockets)


        // Para o mediaChannel se existir
        if ($this->mediaChannel) {
            try {
                $this->mediaChannel->active = false;
                $this->mediaChannel->unblock();
                $this->mediaChannel->close();
            } catch (\Throwable $e) {
                // Ignora erros
            }
        }

        // Para o proxy media se estiver ativo
        if ($this->proxyMediaActive) {
            try {
                $this->stopProxyMedia();
            } catch (\Throwable $e) {
            }
        }

        // Fecha todos os sockets
        foreach ($this->socketsList as $socket) {
            try {
                if ($socket instanceof Socket && !$socket->isClosed()) {
                    $socket->close();
                }
            } catch (\Throwable $e) {
            }
        }

        // Fecha sockets principais
        try {
            if (!$this->socket->isClosed()) {
                $this->socket->close();
            }
        } catch (\Throwable $e) {
        }

        try {
            if (!$this->rtpSocket->isClosed()) {
                $this->rtpSocket->close();
            }
        } catch (\Throwable $e) {
        }

        // Limpa callbacks
        $this->onFailedCallback = null;
        $this->onAnswerCallback = null;
        $this->onRingingCallback = null;
        $this->onHangupCallback = null;

        $this->onDtmfCallable = null;
        $this->dtmfCallbacks = [];


        // Limpa outras propriedades grandes
        $this->bufferAudio = "";
        $this->bufferWriteSound = [];
        $this->box = [];
        $this->members = [];


    }


    public function decodePcmaToPcm(string $input): string
    {
        if ($input === "") {
            return "";
        }
        if (empty($this->alawTable)) {
            $this->initLawTables();
        }
        $pcm = "";
        $len = strlen($input);
        for ($i = 0; $i < $len; $i++) {
            $val = $this->alawTable[ord($input[$i])];
            $pcm .= pack("v", $val);
        }
        return $pcm;
    }

    public function onHangup(callable $callback): void
    {
        $this->onHangupCallback = $callback;
    }

    public function bye(): void
    {
        try {
            if ($this->headers200 && isset($this->headers200['headers']) && !$this->socket->isClosed()) {
                $this->socket->sendto($this->host, $this->port, sip::renderSolution(renderMessages::generateBye($this->headers200['headers'])));
            }
        } catch (\Throwable $e) {
            // Ignora erros
        }
    }

    public function addListener(mixed $receiveIp, string $receivePort): void
    {
        $this->listeners[] = [
            'address' => $receiveIp,
            'port' => $receivePort,
        ];
    }

    public function defineTimeout(int $time): void
    {
        $this->closeCallInTime = $this->timeoutCall + $time;

        $this->idTimers[] = Timer::after($time * 1000, function () {
            if ($this->closing || $this->error) {
                return;
            }
            try {
                $this->bye();
            } catch (\Throwable $e) {
                cli::pcl($e->getMessage());
            }
            $this->close();
        });
    }

    public function extractRTPPayload(string $packet): ?string
    {
        if (strlen($packet) < 12) {
            return null;
        }
        $rtpHeader = unpack("CversionAndPadding/CpayloadTypeAndSeq/nsequenceNumber/Ntimestamp/Nssrc", substr($packet, 0, 12));
        $payloadType = $rtpHeader["payloadTypeAndSeq"] & 0x7f;
        if (!in_array($payloadType, [
            0,
            8,
        ])) {
            return null;
        }
        return substr($packet, 12);
    }

    public function PCMToPCMUConverter(string $pcmData): string
    {
        $pcmuData = "";
        foreach (str_split($pcmData, 2) as $sample) {
            if (strlen($sample) < 2) {
                continue;
            }
            $pcm = unpack("s", $sample)[1];
            $pcmuData .= chr($this->linearToPCMU($pcm));
        }
        return $pcmuData;
    }

    public function linearToPCMU(int $pcm): int
    {
        $sign = $pcm < 0 ? 0x80 : 0;
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
        $mantissa = $pcm >> ($exponent == 0 ? 4 : $exponent + 3) & 0xf;
        return ~($sign | $exponent << 4 | $mantissa) & 0xff;
    }

    public function setCallId(string $callId): void
    {
        $this->callId = $callId;
    }

    public function setCallerId(string $callerId): void
    {
        $this->callerId = $callerId;
    }

    public function declareVolume($ipPort, $user, $c): void
    {
        cli::pcl("Declarando volume para {$ipPort} {$user} {$c}", 'bold_yellow');
        $validate = explode(":", $ipPort);
        if (count($validate) < 2) {
            return;
        }
        if ((int)$validate[1] < 1024) {
            return;
        }
        $this->originVolumes[$ipPort] = $user;
        $this->volumeCodec[$ipPort] = $c;
        if (!array_key_exists($ipPort, $this->volumesAverage)) {
            $this->volumesAverage[$ipPort] = [
                "base" => 0,
                "polo" => 0,
            ];
        } else {
            $this->volumesAverage[$ipPort][$user] = 0;
        }
    }

    public function register(int $maxWait = 5): bool
    {
        if (strlen($this->username) < 1) {
            return true;
        }
        if (strlen($this->password) < 1) {
            return true;
        }


        if ($this->registerCount > 3) {
            return false;
        }
        $res = false;
        $modelRegister = $this->modelRegister();
        $startTimer = time();
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        for ($n = $this->connectTimeout; $n--;) {
            if ($this->closing) {
                return false;
            }
            if (time() - $startTimer > $maxWait) {
                print cli::cl("red", "line 753 timeout");
                return false;
            }


            /** @var ? $peer */
            $res = $this->socket->recvfrom($peer, 1);
            if ($res !== false) {
                break;
            } else {
                $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
            }
        }
        if ($res === false) {
            $uriFrom = $modelRegister["headers"]["From"][0];
            $uriFrom = sip::extractURI($uriFrom);
            $uriFrom["peer"]["host"] = $this->host;
            $uriFrom["peer"]["port"] = $this->port;
            $uriFrom = sip::renderURI($uriFrom);
            print cli::cl("red", "line 754 empty response from {$uriFrom}");
            return false;
        }
        $receive = sip::parse($res);
        if (!array_key_exists("headers", $receive)) {
            return false;
        }
        if (!array_key_exists("WWW-Authenticate", $receive["headers"])) {
            return false;
        }
        $wwwAuthenticate = $receive["headers"]["WWW-Authenticate"][0];
        $nonce = value($wwwAuthenticate, 'nonce="', '"');
        $realm = value($wwwAuthenticate, 'realm="', '"');
        $response = sip::generateResponse($this->username, $realm, $this->password, $nonce, "sip:{$this->host}", $modelRegister["method"]);
        $authorization = "Digest username=\"{$this->username}\", realm=\"{$realm}\", nonce=\"{$nonce}\", uri=\"sip:{$this->host}\", response=\"{$response}\"";
        $modelRegister["headers"]["Authorization"] = [$authorization];
        $this->csq++;
        $modelRegister["headers"]["CSeq"] = [$this->csq . " {$modelRegister['method']}"];
        $modelRegister["headers"]["Via"] = ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=z9hG4bK-" . bin2hex(secure_random_bytes(4))];
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        $startTimer = time();
        for (; ;) {
            if ($this->closing) {
                return false;
            }
            if (time() - $startTimer > $maxWait) {
                print cli::cl("red", "line 753 timeout");
                return false;
            }
            $rec = $this->socket->recvfrom($peer, 1);
            if (!$rec) {
                continue;
            } else {
                $receive = sip::parse($rec);
            }
            if ($receive["method"] == "OPTIONS") {
                $respond = renderMessages::respondOptions($receive["headers"]);
                $this->socket->sendto($this->host, $this->port, $respond);
            } else if ($receive["headers"]["Call-ID"][0] !== $this->callId) {
                continue;
            } else {
                $ignores = ["100"];
                if (!in_array($receive["method"], $ignores)) {
                    break;
                }
            }
        }
        if ($receive["method"] == "200") {
            $this->csq++;
            $this->nonce = $nonce;
            $this->isRegistered = true;
            print cli::color("bold_green", "Telefone registrado com sucesso") . PHP_EOL;
            return true;
        } else {
            print cli::color("bold_red", "Falha ao registrar telefone 7999999999999") . PHP_EOL;
            return false;
        }
    }

/**
     * Construir array REGISTER para registrar no servidor SIP
     *
     * Estrutura:
     * [
     *     "method" => "REGISTER",
     *     "methodForParser" => "REGISTER sip:host SIP/2.0",
     *     "headers" => [
     *         "Via" => ["..."],
     *         "From" => ["<sip:username@host>;tag=..."],
     *         "To" => ["<sip:username@host>"],
     *         "Call-ID" => ["..."],
     *         "CSeq" => ["N REGISTER"],
     *         "Contact" => ["<sip:username@ip:port>"],
     *         "Expires" => ["3600"],
     *         ...
     *     ]
     * ]
     *
     * Se autenticação for requerida:
     * - Servidor responde 401 ou 407 com desafio Digest
     * - Método register() trata a autenticação adicionando Authorization header
     * - CSeq é incrementado
     * - INVITE é reenviado
     *
     * @return array Array de sinalização REGISTER
     *
     * @see register() para lógica de autenticação Digest
     * @see SIGNALING_ARRAYS.md para documentação completa
     */
    private function modelRegister(): array
    {
        $fpp = 5060;
        if ($this->domain) {
            $registerLine = "{$this->domain}";
            $fpee = $this->domain;
            $fpp = $this->port;
            $toLine = "<sip:{$this->username}@{$this->domain}>";
        } else {
            $fpee = $this->localIp;
            $fpp = $this->socketPortListen;
            $registerLine = "{$this->host}";
            $toLine = "<sip:{$this->username}@{$this->host}>";
        }
        return [
            "method" => "REGISTER",
            "methodForParser" => "REGISTER sip:{$registerLine} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=z9hG4bK-" . bin2hex(secure_random_bytes(4))],
                "From" => [sip::renderURI([
                    "user" => $this->username,
                    "peer" => [
                        "host" => $fpee,
                        "port" => $fpp,
                    ],
                    "additional" => ["tag" => bin2hex(secure_random_bytes(8))],
                ])],
                "To" => [$toLine],
                "Max-Forwards" => ["70"],
                "Call-ID" => [$this->callId],
                "CSeq" => [$this->csq . " REGISTER"],
                "Contact" => ["<sip:{$this->username}@{$this->localIp}:{$this->socketPortListen}>"],
                "User-Agent" => [$this->userAgent],
                "Expires" => ["120"],
                "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE, INFO, UPDATE"],
                "Content-Length" => ["0"],
            ],
        ];
    }

    public function sendDtmf(string $digit): bool
    {
        $this->dtmfList[] = $digit;
        return true;
    }

    public function saveBufferToWavFile(string $caminho, string $audioBuffer): void
    {
        go(function ($audioBuffer, $caminho) {
            $audioBuffer = resampler($audioBuffer, $this->frequencyCall, $this->frequencyCall);


            $audio = waveHead3(strlen($audioBuffer), $this->frequencyCall, 1, 1) . $audioBuffer;
            Coroutine::writeFile($caminho, $audio);
        }, $audioBuffer, $caminho);
    }

    public function mixPcmArray(array $chunks): string
    {
        if (count($chunks) < 2) {
            if (isset($chunks[0]) && $chunks[0] instanceof StringObject) {
                return $chunks[0]->toString();
            }
            return $chunks[0] ?? "";
        }
        $stringChunks = [];
        foreach ($chunks as $chunk) {
            if ($chunk instanceof StringObject) {
                $stringChunks[] = $chunk->toString();
            } else {
                $stringChunks[] = $chunk;
            }
        }
        $minLen = min(array_map("strlen", $stringChunks));
        $minLen -= $minLen % 2;
        $result = new StringObject("");
        for ($i = 0; $i < $minLen; $i += 2) {
            $mix = 0;
            foreach ($stringChunks as $buf) {
                $s = unpack("s", substr($buf, $i, 2))[1];
                $mix += $s;
            }
            if ($mix > 32767) {
                $mix = 32767;
            } elseif ($mix < -32768) {
                $mix = -32768;
            }
            $result->append(pack("s", $mix));
        }
        return $result->toString();
    }

    public function registerByeRecovery(array $byeClient, array $destination, $socketPreserve): void
    {
        $this->byeRecovery = [
            "model" => $byeClient,
            "client" => $destination,
            "socket" => $socketPreserve,
        ];
    }

    /**
     * Verifica se o proxy media já está ativo para esta chamada
     */
    public function isProxyMediaActive(): bool
    {
        return $this->proxyMediaActive && !empty($this->currentProxyId);
    }

    /**
     * Obtém o ID do proxy ativo
     */
    public function getProxyId(): ?string
    {
        return $this->currentProxyId;
    }

    /**
     * Força a parada do proxy media
     */
    public function stopProxyMedia(): void
    {
        if ($this->currentProxyId) {
            $rpcClient = new rpcClient();
            $rpcClient->rpcDelete($this->currentProxyId);
            $rpcClient->close();
            cli::pcl("ProxyMedia {$this->currentProxyId} parado forçadamente", 'red');
        }
        $this->proxyMediaActive = false;
        $this->currentProxyId = null;
    }

    public function clearAudioBuffer(): void
    {
        $this->bufferAudio = "";
    }

    public function registerDtmfCallback(string $dtmf, callable $callback): void
    {
        $this->dtmfCallbacks[$dtmf] = $callback;
    }

    public function resetTimeout(): void
    {
        $this->timeoutCall = time();
    }

    /**
     * Construir e enviar REFER para transferir chamada ativa
     *
     * REFER instrui o peer a transferir a chamada para outro número.
     * Usado para IVR, atendimento, etc.
     *
     * Estrutura do array REFER:
     * [
     *     "method" => "REFER",
     *     "methodForParser" => "REFER sip:current@host SIP/2.0",
     *     "headers" => [
     *         "Via" => [...novo branch...],
     *         "From" => ["<sip:username@host>;tag=..."],
     *         "To" => ["<sip:current_dest@host>"],
     *         "Call-ID" => ["...idêntico..."],
     *         "CSeq" => ["N REFER"],
     *         "Refer-To" => ["sip:new_dest@host"],  // ← Destino transferência
     *         "Referred-By" => ["sip:username@host"],
     *         "Event" => ["refer"],
     *         "Contact" => ["<sip:username@localIp>"],
     *         "Content-Length" => ["0"]
     *     ]
     * ]
     *
     * Fluxo de Transferência:
     * 1. Cliente A chama Cliente B (conectado)
     * 2. Cliente A envia REFER para B, indicando: "transfira para C"
     * 3. Servidor responde 202 Accepted
     * 4. Servidor (ou B) inicia nova chamada para C
     * 5. Quando C atende, servidor desconecta A (ou aguarda BYE de A)
     * 6. B fica livre para nova chamada
     *
     * @param string $to Número destino da transferência (ex: "5511888888888")
     *
     * @return bool|null Sucesso no envio do REFER via UDP
     *
     * @example
     * // Transferir a chamada atual para outro número
     * $phone->transfer('5511888888888');
     *
     * @see transferGroup() para transferência para grupo de agentes
     * @see SIGNALING_ARRAYS.md para documentação completa da estrutura
     */
    public function transfer(string $to): ?bool
    {
        $originTo = $this->calledNumber;
        $modelRefer = [
            "method" => "REFER",
            "methodForParser" => "REFER sip:{$originTo}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP {$this->localIp}:5060;branch=z9hG4bK-" . bin2hex(secure_random_bytes(4))],
                "From" => ["<sip:{$this->username}@{$this->host}>;tag=" . bin2hex(secure_random_bytes(8))],
                "To" => ["<sip:{$originTo}@{$this->host}>"],
                "Call-ID" => [$this->callId],
                "Event" => ["refer"],
                "CSeq" => [$this->csq . " REFER"],
                "Contact" => ["<sip:{$this->username}@{$this->localIp}>"],
                "Refer-To" => ["sip:{$to}@{$this->host}"],
                "Referred-By" => ["sip:{$this->username}@{$this->host}"],
                "Content-Length" => ["0"],
            ],
        ];
        return $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRefer));
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
        $excluded = [];
        $callsContent = json_decode(file_get_contents($callsFile), true);
        foreach ($callsContent as $callId => $data) {
            $excluded = array_merge($excluded, array_keys($data));
        }
        $timeout = 35;
        $startTime = time();
        do {
            $connections = json_decode(@file_get_contents($connectionsFile), true);
            if (!is_array($connections)) {
                $connections = [];
            }
            foreach ($agents as $idAgent => $agent) {
                if (!array_key_exists($agent, $connections)) {
                    unset($agents[$idAgent]);
                    continue;
                }
                if ($agent === $this->username) {
                    unset($agents[$idAgent]);
                    continue;
                }
                if (in_array($agent, $excluded)) {
                    unset($agents[$idAgent]);
                    continue;
                }
            }
            if (!empty($agents)) {
                break;
            }
            interruptibleSleep(0.1, $this->callActive);
        } while (time() - $startTime < $timeout);
        if (empty($agents)) {
            $this->resetTimeout();
            $baseDir = \Extension\plugins\utils::baseDir();


            return $this->transferGroup($groupName, $retry);
        }
        foreach ($agents as $agent) {
            (function ($agent) {
                echo "✅ Transferindo chamada para {$agent}...\n";
                $this->transfer($agent);
            })($agent);
        }
        cli::pcl("Já saiu do loop");
    }


    /**
     * Define o callback para processamento do áudio recebido
     * @param callable $param Função com a assinatura function(string $pcmData, array $peer, trunkController $phone): void}
     */
    public function onReceivePcm(callable $param)
    {
        $this->onReceivePcmCallback = $param;
    }

    public $onReceivePcmCallback;

    public function defineAudioFile(string $audioFile): void
    {
        try {
            \libspech\Sip\secureAudioVoip($audioFile);
        } catch (\Exception $e) {
            cli::pcl("Error defining audio file: " . $e->getMessage());
            return;
        }

        $infoFile = \libspech\Sip\getInfoAudio($audioFile);
        $tags = \libspech\Sip\wavChunks($audioFile);

        $idDataTag = array_find_key($tags, fn($tag) => $tag['id'] === 'data');
        if ($idDataTag === null) {
            cli::pcl("Error: WAV data chunk not found");
            return;
        }

        $chunkSize = \libspech\Sip\calculateChunkSize(
            $infoFile['rate'],
            $infoFile['numChannels'],
            $infoFile['bitDepth']
        );

        $dataOffset = $tags[$idDataTag]['data'];

        // 🔥 Lê o WAV inteiro em memória
        $fileData = file_get_contents($audioFile);
        if ($fileData === false) {
            cli::pcl("Error reading audio file");
            return;
        }

        // Apenas a parte PCM pura
        $audioData = substr($fileData, $dataOffset);
        unset($fileData);

        $audioLen = strlen($audioData);

        // 🌀 Começa no zero
        $currentPosition = 0;

        $this->registerAudioEvent(function ($pcmData, $peer, trunkController $phone)
        use (&$currentPosition, $audioData, $audioLen, $chunkSize, $infoFile) {
            if (empty($this->callActive)) {
                cli::pcl("Call is not active, stopping audio playback.");
                return;
            }

            $idFrom = $peer['address'] . ':' . $peer['port'];
            $frequencyPacket = $infoFile['rate'];
            $frequencyMember = $phone->frequencyCall;
            $ssrc = $phone->mediaChannel->members[$idFrom]['ssrc'];

            // --- LOOP INFINITO LIMPO ---
            if ($currentPosition + $chunkSize > $audioLen) {
                // Parte faltando
                $rest = $audioLen - $currentPosition;

                if ($rest > 0) {
                    $part1 = substr($audioData, $currentPosition, $rest);
                } else {
                    $part1 = '';
                }

                // Completa com início do arquivo
                $part2 = substr($audioData, 0, $chunkSize - $rest);

                $pcmChunk = $part1 . $part2;

                // Reinicia posição
                $currentPosition = ($chunkSize - $rest);
            } else {
                // Chunk normal
                $pcmChunk = substr($audioData, $currentPosition, $chunkSize);
                $currentPosition += $chunkSize;
            }

            // ----------------------------
            // Codec processing
            // ----------------------------

            switch (strtoupper($phone->codecName)) {

                case 'PCMU':
                    if ($frequencyPacket !== 8000) {
                        $pcmChunk = resampler($pcmChunk, $frequencyPacket, 8000);
                    }
                    $encode = encodePcmToPcmu($pcmChunk);
                    break;

                case 'PCMA':
                    if ($frequencyPacket !== 8000) {
                        $pcmChunk = resampler($pcmChunk, $frequencyPacket, 8000);
                    }
                    $encode = encodePcmToPcma($pcmChunk);
                    break;

                case 'G729':
                    if ($frequencyPacket !== 8000) {
                        $pcmChunk = resampler($pcmChunk, $frequencyPacket, 8000);
                    }
                    $encode = $phone->mediaChannel->rtpChans[$ssrc]
                        ->bcg729Channel->encode($pcmChunk);
                    break;

                case 'OPUS':
                    if ($frequencyPacket !== 48000) {
                        $pcm48 = resampler($pcmChunk, $frequencyPacket, 48000);
                    } else {
                        $pcm48 = $pcmChunk;
                    }
                    $encode = $phone->mediaChannel->members[$idFrom]['opus']
                        ->encode($pcm48);
                    break;

                case 'L16':
                    $encode = resampler($pcmChunk, $frequencyPacket, $frequencyMember, true);
                    break;

                default:
                    return;
            }

            if (!$encode) return;

            $packet = $phone->rtpChannel->buildAudioPacket($encode);
            $this->rtpSocket->sendto($peer['address'], $peer['port'], $packet);
        });
    }


    /**
     * Extrai PCM bruto e informações do arquivo WAV
     * @param string $wavFile Caminho do arquivo WAV
     * @return array ['pcm' => string, 'sampleRate' => int, 'bitsPerSample' => int, 'numChannels' => int, 'chunkSize' => int]
     */
    public function loadWavFile(string $wavFile): array
    {
        if (!file_exists($wavFile)) {
            throw new \Exception("Arquivo WAV não encontrado: $wavFile");
        }

        $wavContent = file_get_contents($wavFile);

        // Extrair informações do header WAV
        $wavInfo = [
            'riff' => substr($wavContent, 0, 4),
            'fileSize' => unpack('V', substr($wavContent, 4, 4))[1],
            'wave' => substr($wavContent, 8, 4),
            'audioFormat' => unpack('v', substr($wavContent, 20, 2))[1], // 1 = PCM
            'numChannels' => unpack('v', substr($wavContent, 22, 2))[1],
            'sampleRate' => unpack('V', substr($wavContent, 24, 4))[1],
            'byteRate' => unpack('V', substr($wavContent, 28, 4))[1],
            'blockAlign' => unpack('v', substr($wavContent, 32, 2))[1],
            'bitsPerSample' => unpack('v', substr($wavContent, 34, 2))[1],
        ];

        // Encontrar chunk "data"
        $dataPos = strpos($wavContent, 'data', 36);
        if ($dataPos === false) $dataPos = 36;

        $wavInfo['dataSize'] = unpack('V', substr($wavContent, $dataPos + 4, 4))[1];
        $headerSize = $dataPos + 8;

        // Extrair PCM bruto
        $rawPcm = substr($wavContent, $headerSize);


        // Calcular tamanho do chunk para 20ms
        $bytesPerSample = $wavInfo['bitsPerSample'] / 8;
        $chunkSize = (int)($wavInfo['sampleRate'] * 0.02 * $wavInfo['numChannels'] * $bytesPerSample);

        return [
            'pcm' => $rawPcm,
            'sampleRate' => $wavInfo['sampleRate'],
            'bitsPerSample' => $wavInfo['bitsPerSample'],
            'numChannels' => $wavInfo['numChannels'],
            'chunkSize' => $chunkSize,
        ];
    }

    public function getCid()
    {
        return $this->cid;
    }


    private function generateEmptyWavFile(string $path, int $durationSec): void
    {
        $fakeData = str_repeat(chr(0), $durationSec * 8000);
        file_put_contents($path, waveHead(strlen($fakeData), 8000, 1, 1) . $fakeData);
    }

    /**
     * Envia DTMF via RFC 2833 usando a tabela com 3 pacotes.
     */
    private function sendDtmfRfc2833(string $digit, Socket $rtpSocket, string $remoteIp, int $remotePort, int $ssrc): void
    {
        $model = $this->getModelDTMF2833Table($digit);
        foreach ($model as $packet) {
            $binary = $this->generateDtmfPacket($packet["digit"], $packet["end"], $packet["volume"], $packet["duration"]);
            $rtpSocket->sendto($remoteIp, $remotePort, $binary);
            sleep(0.02);
        }
    }

    private function getModelDTMF2833Table(string $digit): array
    {
        $durationPerPacket = 160;
        $totalDuration = $durationPerPacket * 3;
        $packets = [];
        for ($i = 1; $i <= 2; $i++) {
            $packets[] = [
                "digit" => $digit,
                "end" => false,
                "volume" => 0x0,
                "duration" => $i * $durationPerPacket,
            ];
        }
        $packets[] = [
            "digit" => $digit,
            "end" => true,
            "volume" => 0x0,
            "duration" => $totalDuration,
        ];
        return $packets;
    }

    /**
     * Gera um pacote RTP DTMF conforme RFC 2833.
     */
    public function generateDtmfPacket(string $dtmf, bool $endOfEvent = false, int $volume = 0x0, int $duration = 400): string
    {
        $payloadType = 101;
        $ssrc = $this->ssrc;
        $rtpHeader = pack("CCnNN", 0x80, $payloadType, $this->sequenceNumber++, $this->timestamp, $ssrc);
        $this->timestamp += 160;
        $event = is_numeric($dtmf) ? (int)$dtmf : ord($dtmf);
        $eventInfo = ($endOfEvent ? 0x80 : 0x0) | $volume & 0x3f;
        $dtmfPayload = pack("CCn", $event, $eventInfo, $duration);
        return $rtpHeader . $dtmfPayload;
    }

    private function registerAudioEvent(Closure $param)
    {
        $this->audioFileHandle = $param;
    }
}