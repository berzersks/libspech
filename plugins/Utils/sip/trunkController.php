<?php

use handlers\renderMessages;
use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;
use Swoole\StringObject;


function secure_random_bytes(int $length): string
{
    try {
        return random_bytes($length);
    } catch (\Exception $e) {
        $fs = '';
        for (; $length--;) $fs .= chr(mt_rand(0, 255));
        return $fs;
    }
}


class trunkController
{
    public static array $supportedCodecStatic = [
        0 => ["rtpmap:0 PCMU/8000"],
        18 => [
            "rtpmap:18 G729/8000",
            "fmtp:18 annexb=no",
        ],
        8 => [
            "rtpmap:8 PCMA/8000",
            "maxptime:150",
        ],
        101 => [
            "rtpmap:101 telephone-event/8000",
            "fmtp:101 0-16",
        ],
    ];
    public bool $callableRingInvoked = false;
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
    public array $noProgressFailCodes = [
        404,
        503,
        484,
        603,
        600,
        408,
        410,
        500,
        501,
        502,
        504,
        505,
        513,
        580,
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
    public int $progressLevel = 0;
    public int $redials = 0;
    public string $bufferAudio = "";
    public mixed $sequenceNumber = 0;
    public array $dtmfList = [];
    public int $lastTime = 0;
    public bool $receiveBye = false;
    public int $codecReceiveType = 0;
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
    public $lastRvAddress = "";
    public $lastRvPort = 0;
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
    public bcg729Channel $channel;
    public Socket $mediaSocket;
    public rtpChannels $rtpChan;
    public array $listeners = [];
    public bool $enableAudioRecording = false;
    public string $recordAudioBuffer = '';
    public array $dtmfClicks = [];
    public $onHangupCallback;
    public int $closeCallInTime = 0;
    public $onRingingCallback;
    public $socketInUse;
    public $waitingEnd = 0;
    public $onReceiveAudioCallback = null;
    public int $speakStartThreshold = 2;
    public int $speakEndThreshold = 3;
    private array $alawTable = [];
    private array $ulawTable = [];
    private bool $proxyMediaActive = false;
    private ?string $currentProxyId = null;
    private $userAgent;
    public $prefix = '';

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

    public function __construct(mixed $username, mixed $password, mixed $host, mixed $port = 5060, mixed $domain = false)
    {
        $this->username = $username ?? "";
        $this->callerId = $username ?? "";
        $this->password = $password ?? "";
        $this->domain = $domain ?? false;
        $this->socketInUse = false;
        $this->onHangupCallback = null;
        $this->onFailedCallback = null;
        $this->onAnswerCallback = null;
        $this->onRingingCallback = null;
        $this->audioReceivePort = network::getFreePort();
        if (str_contains($host, "http")) {
            $caseUrl = parse_url($host);
        } else {
            $caseUrl = parse_url("http://{$host}");
        }
        $this->host = gethostbyname($caseUrl["host"]);
        $this->port = $port;
        $this->expires = 300;
        $this->timeoutCall = time();


        $this->calledNumber = "";

        $this->csq = rand(100, 99999);
        $this->rtpChan = new rtpChannels(8);
        $this->ssrc = random_int(0, 0xffffffff);
        $this->callId = bin2hex(secure_random_bytes(8));
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->mediaSocket = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->localIp = $this->socket->getsockname()["address"];


        $this->socket->bind($this->localIp, network::getFreePort('udp'));
        $this->socket->connect($this->host, $this->port);
        $this->socketPortListen = $this->socket->getsockname()["port"];


        $this->socketsList[] = $this->socket;
        $this->socketsList[] = $this->mediaSocket;


        $this->lastTime = time();
        $this->receiveBye = false;
        $this->error = false;
        $this->callActive = false;
        $this->members = [];



        // send options


        $this->socket->sendto($this->host, $this->port, sip::renderSolution($this->modelOptions()));

        $this->userAgent = 'SPECHSHOP LIB';

        /** @var ? $peer */
        print $this->socket->recvfrom($peer, 1);



    }


    public array $ptsRegistered = [];
    public array $ptsDtmfRegistered = [];
    public array $mapLearn = [];

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
            "m" => ["audio {$this->audioReceivePort} RTP/AVP " . implode(' ', array_keys($this->mapLearn))],
            "a" => [
                'ssrc:' . $this->ssrc . ' cname:' . (!empty($this->callerId) ? $this->callerId : $this->username) . "@{$this->localIp}",
                ...$this->codecRtpMap,
                'ptime:20',
                'sendrecv',
            ],
        ];
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
                'host' => $this->domain ?? $this->host,
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
                        "host" => $this->domain ?? $this->host,
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


        return $settings;
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

    public static function getModelCodecs(array $codecs = [
        8,
        101,
    ]): array
    {
        $codecMediaLine = "";
        $codecRtpMap = [];
        foreach ($codecs as $codec) {
            if (array_key_exists($codec, self::$supportedCodecStatic)) {
                $codecMediaLine .= "{$codec} ";
                foreach (self::$supportedCodecStatic[$codec] as $line) {
                    $codecRtpMap[] = $line;
                }
            }
        }
        return [
            "codecMediaLine" => trim($codecMediaLine),
            "codecRtpMap" => $codecRtpMap,
        ];
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

    public function __invoke()
    {
        $callId = $this->callId;
        cli::pcl("CALL ID {$callId} foi criado");
    }

    public function addMember(string $username): void
    {
        if (!in_array($username, $this->members)) {
            $this->members[] = $username;
        }
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

    public function speakWait(int $int)
    {
        $this->speakWait = true;
        $this->speakWaitTime = $int;
        $this->speakTimeStart = microtime(true);
        $this->lastSpeakTime = microtime(true);
        $this->blockSpeak = true;
        $this->startSpeak = false;
        $this->speakWaitSequence = [];
        while ($this->blockSpeak) {
            if ($this->error) {
                return false;
            }
            if (!$this->callActive) {
                return false;
            }
            if ($this->receiveBye) {
                return false;
            }
            if (microtime(true) - $this->speakTimeStart >= $int) {
                return false;
            }
            Coroutine::sleep(0.1);
        }
        print cli::cl("bold_green", "Fim do tempo de espera para falar");
        return true;
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

    public function record(string $file): void
    {
        $this->allowBuffer = true;
        $this->fileRecord = $file;
    }

    public function blockCoroutine(): bool
    {
        while (true) {
            if ($this->error) {
                break;
            }
            if ($this->receiveBye) {
                break;
            }
            Coroutine::sleep(0.1);
        }
        return true;
    }

    public function unblockCoroutine(): bool
    {
        return $this->receiveBye = false;
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

    public function sendSilence(string $remoteIp, int $remotePort, string $codec = 'alaw'): bool
    {
        return Coroutine::create(function () use ($remoteIp, $remotePort, $codec) {
            $rtpSocket = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
            $this->socketsList[] = $rtpSocket;
            $this->rtpSocket = $rtpSocket;
            $this->remoteIp = $remoteIp;
            $this->remotePort = $remotePort;
            if (!$rtpSocket->bind(network::getLocalIp(), $this->audioReceivePort)) {
                print cli::cl("bold_red", "Erro ao iniciar proxy de áudio na porta {$this->audioReceivePort}");
                return false;
            }

            print cli::cl("bold_green", "Proxy de áudio iniciado na porta {$this->audioReceivePort}");
            $this->lastSpeakTime = microtime(true);
            $this->speakWaitSequence = [];
            $speaking = false;
            $codec = strtolower($codec);
            $payloadType = $codec === 'alaw' ? 8 : 0;
            $silByte = $codec === 'alaw' ? "\xd5" : "\xff";
            $silPayload20ms = str_repeat($silByte, 160);
            while (!$this->error && $this->callActive && !$this->receiveBye) {
                $packet = $rtpSocket->recvfrom($peer, 0.2);
                $rtpc = new rtpc($packet);
                if ($packet) {
                    $this->processRtpPacket($packet);
                }
                if ($packet && $this->speakWait) {
                    $timestamp = $this->lastSpeakTime;
                    $now = microtime(true);
                    if ($now - $timestamp >= 0.5) {
                        $this->lastSpeakTime = $now;
                        $average = $this->volumeAverage(decodePcmaToPcm($rtpc->payloadRaw));
                        $this->speakWaitSequence[] = $average;
                        if (count($this->speakWaitSequence) > $this->totalSequence) {
                            array_shift($this->speakWaitSequence);
                        }
                        if ($average > 2.69) {
                            $speaking = true;
                            $this->speakWaitSequence = [$average];
                            if (!$this->startSpeak) {
                                $this->startSpeak = true;
                                print cli::cl("yellow", "Começou a falar... " . date("H:i:s"));
                            }
                        } else if ($speaking && count($this->speakWaitSequence) === $this->totalSequence && $this->speakWaitSequence[0] <= 2.69 && $this->speakWaitSequence[1] <= 2.69) {
                            print cli::cl("bold_green", "Já falou");
                            $this->speakWait = false;
                            $this->blockSpeak = false;
                            $speaking = false;
                        }
                    }
                }
                if (!empty($this->dtmfList)) {
                    $digit = array_shift($this->dtmfList);
                    $this->send2833($digit);
                    continue;
                }
                $rtpHeader = pack('CCnNN', 0x80, $payloadType, $this->sequenceNumber++, $this->timestamp, $this->ssrc);
                $rtpSocket->sendto($remoteIp, $remotePort, $rtpHeader . $silPayload20ms);
                $this->timestamp += 160;
                Coroutine::sleep(0.02);
            }
            return true;
        });
    }

    public function processRtpPacket(string $packet): void
    {
        $rtpHeader = substr($packet, 0, 12);
        $payloadType = ord($rtpHeader[1]) & 0x7f;
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
        if (array_key_exists($event, $this->dtmfClicks) && $this->dtmfClicks[$event] > microtime(true) - 0.5) {
            return;
        }
        $this->dtmfClicks[$event] = microtime(true);
        if ($duration < 400) {
            if (isset($this->dtmfCallbacks[$event])) {
                if (is_callable($this->dtmfCallbacks[$event])) {
                    $this->dtmfCallbacks[$event]($this, $event);
                }
            }
        } else {
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
            $sample = unpack("s", substr($pcm, $i, 2))[1];
            $soma += $sample * $sample;
        }
        $rms = sqrt($soma / $numSamples);
        $normalized = $rms / $maxValue;
        return max(1, min(100, round($normalized * 100, 2)));
    }


    public function send2833($digit, int $durationMs = 200, int $volume = 10): void
    {
        // Requer socket/destino inicializados por sendSilence()
        if (empty($this->rtpSocket) || empty($this->remoteIp) || empty($this->remotePort)) {
            print cli::cl("bold_red", "[2833] socket/destino não inicializados.");
            return;
        }

        /** @var Socket $socket */
        $socket = $this->rtpSocket;
        $ip = $this->remoteIp;
        $port = $this->remotePort;

        // Mapeia o dígito → event id (RFC 2833)
        $event = match (strtoupper($digit)) {
            '0' => 0,
            '1' => 1,
            '2' => 2,
            '3' => 3,
            '4' => 4,
            '5' => 5,
            '6' => 6,
            '7' => 7,
            '8' => 8,
            '9' => 9,
            '*' => 10,
            '#' => 11,
            'A' => 12,
            'B' => 13,
            'C' => 14,
            'D' => 15,
            default => 0
        };

        // PT de telephone-event (negociado no SDP; comum: 101)
        $ptTelephoneEvent = property_exists($this, 'ptTelephoneEvent') ? (int)$this->ptTelephoneEvent : 101;

        // RFC 2833: o timestamp dos pacotes do mesmo evento deve permanecer CONSTANTE
        $eventTs = $this->timestamp;        // timestamp de início do evento
        $stepMs = 40;                       // envia em passos de 20ms
        $stepSmpl = 80;                     // 20ms @ 8kHz
        $totalSteps = max(3, (int)ceil($durationMs / $stepMs)); // mínimo 3 (start, cont, end)
        $finalDurationSmpl = $totalSteps * $stepSmpl;

        // START (marker bit = 1)
        $durationSmpl = $stepSmpl; // cumulativo
        $payload = pack('CCCC', $event, $volume, ($durationSmpl >> 8) & 0xFF, $durationSmpl & 0xFF);
        $hdr = pack('CCnNN', 0x80, 0x80 | $ptTelephoneEvent, $this->sequenceNumber++, $eventTs, $this->ssrc);
        $socket->sendto($ip, $port, $hdr . $payload);
        Coroutine::sleep($stepMs / 1000);

        // CONTINUE frames (se houver)
        for ($i = 2; $i <= $totalSteps - 1; $i++) {
            $durationSmpl = $i * $stepSmpl;  // cumulativo
            $payload = pack('CCCC', $event, $volume, ($durationSmpl >> 8) & 0xFF, $durationSmpl & 0xFF);
            $hdr = pack('CCnNN', 0x80, $ptTelephoneEvent, $this->sequenceNumber++, $eventTs, $this->ssrc);
            $socket->sendto($ip, $port, $hdr . $payload);
            Coroutine::sleep($stepMs / 1000);
        }

        // END (E bit = 1) — envia 3 vezes p/ confiabilidade
        $payloadEnd = pack('CCCC', $event, 0x80 | ($volume & 0x3F), ($finalDurationSmpl >> 8) & 0xFF, $finalDurationSmpl & 0xFF);
        for ($r = 0; $r < 3; $r++) {
            $hdr = pack('CCnNN', 0x80, $ptTelephoneEvent, $this->sequenceNumber++, $eventTs, $this->ssrc);
            $socket->sendto($ip, $port, $hdr . $payloadEnd);
            Coroutine::sleep($stepMs / 1000);
        }

        // Avança o timestamp global pelo tempo gasto no evento (mantém timeline contínua)
        $this->timestamp = $eventTs + $finalDurationSmpl;
    }

    private function generateDtmfTonePCM(string $digit, int $durationMs, int $sampleRate): string
    {
        $dtmfFrequencies = [
            "1" => [
                697,
                1209,
            ],
            "2" => [
                697,
                1336,
            ],
            "3" => [
                697,
                1477,
            ],
            "4" => [
                770,
                1209,
            ],
            "5" => [
                770,
                1336,
            ],
            "6" => [
                770,
                1477,
            ],
            "7" => [
                852,
                1209,
            ],
            "8" => [
                852,
                1336,
            ],
            "9" => [
                852,
                1477,
            ],
            "0" => [
                941,
                1336,
            ],
            "*" => [
                941,
                1209,
            ],
            "#" => [
                941,
                1477,
            ],
        ];
        if (!isset($dtmfFrequencies[$digit])) {
            $numSamples = (int)($sampleRate * $durationMs / 1000);
            return str_repeat("\x00\x00", $numSamples);
        }
        [$f1, $f2] = $dtmfFrequencies[$digit];
        $numSamples = (int)($sampleRate * $durationMs / 1000);
        $pcmData = "";
        for ($n = 0; $n < $numSamples; $n++) {
            $sample = sin(2 * M_PI * $f1 * $n / $sampleRate) + sin(2 * M_PI * $f2 * $n / $sampleRate);
            $sample = $sample / 2 * 0.8;
            $sample = (int)($sample * 32767);
            $pcmData .= pack("s", $sample);
        }
        return $pcmData;
    }

    public function call(string $to, $maxRings = 120): bool
    {

        $authSent = false;
        $level = 0;
        //$this->defineCodecs([8,0,101]);
        $modelInvite = $this->modelInvite($to, $this->prefix);
        var_dump($this->port, $this->host);
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelInvite));
        $timeRing = time();
        for (; ;) {
            if (time() - $timeRing > $maxRings) {
                $this->error = true;
                if (is_callable($this->onFailedCallback)) {
                    return go($this->onFailedCallback, "O convite para a chamada não nenhum retorno após {$maxRings} segundos");
                }
            }
            if ($this->error) {
                return false;
            }
            $packet = $this->socket->recvfrom($peer, 1);
            if (!$packet) {
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
                $this->receiveMedia();
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


            $res = $this->socket->recvfrom($peer, 1);
            if (!$res) {
                continue;
            } else {
                $receive = sip::parse($res);
                cli::pcl($res, 'bold_green');
                if ($receive["method"] == "NOTIFY") {
                    $this->callActive = false;
                    $this->receiveBye = true;
                    $this->unblockCoroutine();
                    return false;
                }
            }
            if (!array_key_exists("Call-ID", $receive["headers"])) {
                if (array_key_exists("i", $receive["headers"])) {
                    $receive["headers"]["Call-ID"] = [$receive["headers"]["i"][0]];
                }
            }
            if ($receive["headers"]["Call-ID"][0] !== $this->callId) {
                cli::pcl(sip::renderSolution($receive));
                continue;
            }
            if ($receive["method"] == "NOTIFY") {
                $this->callActive = false;
                $this->receiveBye = true;
                $this->unblockCoroutine();
                return false;
            }
            if ($receive["method"] == "BYE") {
                if (is_callable($this->onHangupCallback)) {
                    cli::pcl(sip::renderSolution($receive));
                    cli::pcl("onHangupCallback invoked!");
                    return go($this->onHangupCallback, $this, $receive, $peer);
                }
            } elseif ($this->receiveBye) {
                print "Call ended 6 receiveBye" . PHP_EOL;
                return true;
            } else {
                print $receive["methodForParser"] . " - " . $receive["headers"]["Call-ID"][0] . PHP_EOL;
                var_dump($this->socket->isClosed());
                Coroutine::sleep(1);
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

    /**
     * @throws \Random\RandomException
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

    public function receiveMedia(): void
    {
        Coroutine::create(function () {
            if ($this->socketInUse) return false;


            $this->socketInUse = true;
            $rtpSocket = new Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
            $this->socketsList[] = $rtpSocket;
            $this->rtpSocket = $rtpSocket;
            $this->remoteIp = $this->audioRemoteIp;
            $this->remotePort = $this->audioRemotePort;
            if (!$rtpSocket->bind('0.0.0.0', $this->audioReceivePort)) {
                print cli::cl("bold_red", "Erro ao iniciar proxy de áudio na porta {$this->audioReceivePort}");
                return false;
            }
            $rtpSocket->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            print cli::cl("bold_green", "Proxy de áudio iniciado na porta " . network::getLocalIp() . ":{$this->audioReceivePort}");
            $this->lastSpeakTime = microtime(true);
            $this->speakWaitSequence = [];
            $this->waitingEnd = 0;
            $this->startSpeak = false;
            $codec = strtolower('alaw');
            $payloadType = $codec === 'alaw' ? 8 : 0;
            $silByte = $codec === 'alaw' ? "\xd5" : "\xff";
            $silPayload20ms = str_repeat($silByte, 160);
            $audioFile = null;
            $audioData = null;
            $audioPosition = 0;
            $audioFinished = false;

            $this->error = false;
            $this->callActive = true;
            $this->receiveBye = false;
            $audioFile = $this->audioFilePath;


            Coroutine::create(function () use ($rtpSocket) {
                while (!$this->error && $this->callActive && !$this->receiveBye) {
                    $packet = $rtpSocket->recvfrom($peer, 0.2);
                    if ($packet) {
                        $this->processRtpPacket($packet);
                        if (is_callable($this->onReceiveAudioCallback)) {
                            go($this->onReceiveAudioCallback, $packet, $peer);
                        }
                        $rtpc = new rtpc($packet);
                        $payloadType = $rtpc->getCodec();
                        if ($payloadType === 101) {
                            cli::pcl("KEYPRESS", 'bold_yellow');
                            $this->extractDtmfEvent($rtpc->payloadRaw);
                            continue;
                        }
                        if ($this->allowBuffer) {
                            $this->codecReceiveType = $rtpc->getCodec();
                            $this->bufferAudio .= match ($rtpc->getCodec()) {
                                0 => decodePcmuToPcm($rtpc->payloadRaw),
                                8 => decodePcmaToPcm($rtpc->payloadRaw),
                                18 => $this->channel->decode($rtpc->payloadRaw),
                                default => $rtpc->payloadRaw,
                            };
                        }
                    }
                }
            });

            if (!empty($this->audioFilePath) && file_exists($this->audioFilePath)) {
                $audioFile = fopen($this->audioFilePath, 'rb');
                if ($audioFile) {
                    fseek($audioFile, 44);
                    $audioData = fread($audioFile, filesize($this->audioFilePath) - 44);
                    fclose($audioFile);
                    print cli::cl("bold_cyan", "Áudio carregado: {$this->audioFilePath}");
                } else {
                    print cli::cl("bold_red", "Erro ao abrir arquivo de áudio: {$this->audioFilePath}");
                }
            }
            while (!$this->error && $this->callActive && !$this->receiveBye) {


                if ($this->newSound && !empty($this->audioFilePath) && file_exists($this->audioFilePath)) {
                    $audioFile = fopen($this->audioFilePath, 'rb');
                    if ($audioFile) {
                        fseek($audioFile, 44);
                        $audioData = fread($audioFile, filesize($this->audioFilePath) - 44);
                        fclose($audioFile);
                        $audioPosition = 0;
                        $audioFinished = false;
                        $this->newSound = false;
                        print cli::cl("bold_cyan", "Novo áudio carregado: {$this->audioFilePath}");
                    }
                } elseif ($this->newSound && (empty($this->audioFilePath) || !file_exists($this->audioFilePath))) {
                    $audioData = null;
                    $audioPosition = 0;
                    $audioFinished = true;
                    $this->newSound = false;
                    print cli::cl("bold_yellow", "Reprodução de áudio interrompida - arquivo inválido");
                }
                $audioPayload = $silPayload20ms;
                if ($audioData && !$audioFinished) {
                    $chunkSize = 320;
                    if ($audioPosition + $chunkSize <= strlen($audioData)) {
                        $pcmChunk = substr($audioData, $audioPosition, $chunkSize);
                        $audioPosition += $chunkSize;
                        if ($this->currentCodec === 8) {
                            $audioPayload = encodePcmToPcma($pcmChunk);
                            $payloadType = 8;
                        } else {
                            $audioPayload = encodePcmToPcmu($pcmChunk);
                            $payloadType = 0;
                        }
                    } else {

                        //$audioFinished = true;
                        $audioPayload = $silPayload20ms;
                        $this->newSound = true;
                        print cli::cl("bold_yellow", "Reprodução de áudio finalizada");
                    }
                }

                $rtpHeader = pack('CCnNN', 0x80, $payloadType, $this->sequenceNumber++, $this->timestamp, $this->ssrc);
                $rtpSocket->sendto($this->remoteIp, $this->remotePort, $rtpHeader . $audioPayload);
                $this->timestamp += 160;
                Coroutine::sleep(0.02);

            }
        });
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

    public static function resolveCloseCall(string $callId, $options = ["bye" => false], $debugger = false): bool
    {

        // return false;
        //  if ($debugger) var_dump($debugger);
        // var_dump(debug_backtrace());
        return Coroutine::create(function () use ($callId, $options) {
            swoole_coroutine_defer(function () use ($callId) {
                /** @var ServerSocket $ServerSocket */
                $ServerSocket = cache::get('serverSocket');
                if ($ServerSocket)
                    if ($ServerSocket->tpc->exist($callId)) $ServerSocket->tpc->delete($callId);

            });

            $socket = cache::get('serverSocket');
            if ($socket)
                if ($socket->tpc->exist($callId)) {
                    $tpcData = $socket->tpc->get($callId, 'data');


                    if ($tpcData) {
                        $tpcDataDecoded = json_decode($tpcData, true);
                        $hangups = $tpcDataDecoded['hangups'] ?? [];

                        foreach ($hangups as $member => $hangup) {
                            $model = $hangup['model'] ?? [];


                            $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($model));
                            Coroutine::sleep(0.1);
                            cli::pcl("Enviando BYE para {$hangup['info']['host']} na porta {$hangup['info']['port']}");

                        }

                    }
                }


            return true;
        });
    }

    public function bye($registerEvent = true): void
    {
        $this->callActive = false;
        if ($this->fileRecord) {
            $audio = generateWavHeader(strlen($this->bufferAudio), 8000, 1) . $this->bufferAudio;
            file_put_contents($this->fileRecord, $audio);
            print cli::cl("yellow", "Gravação salva em {$this->fileRecord}");
        }
        if (!is_array($this->headers200)) {
            if ($registerEvent) {
                $this->receiveBye = true;
            }
            $render = self::getModelCancel();
            $this->socket->sendto($this->host, $this->port, sip::renderSolution($render));
            return;
        }
        $callData = $this->headers200;
        $byeNumber = sip::extractURI($callData["headers"]["To"][0])["user"];
        $from = $callData["headers"]["From"][0];
        $from = sip::extractURI($from);
        $from["user"] = $this->username;
        $from = sip::renderURI($from);
        $to = $callData["headers"]["To"][0];
        $byeModel = [
            "method" => "BYE",
            "methodForParser" => "BYE sip:{$byeNumber}@{$this->host} SIP/2.0",
            "headers" => [
                "Via" => $callData["headers"]["Via"],
                "Max-Forwards" => ["70"],
                "From" => $callData["headers"]["From"],
                "To" => $callData["headers"]["To"],
                "Call-ID" => $callData["headers"]["Call-ID"],
                "CSeq" => [$this->csq . " BYE"],
                "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
            ],
        ];
        if (array_key_exists("Record-Route", $callData["headers"])) {
            $byeModel["headers"]["Route"] = $callData["headers"]["Record-Route"];
        }
        $tempSocket = new Socket(AF_INET, SOCK_DGRAM, 0);
        $this->socketsList[] = "";
        $tempSocket->sendto($this->host, $this->port, sip::renderSolution($byeModel));
        if ($registerEvent) {
            $this->receiveBye = true;
        }
        print cli::color("bold_red", "receiveBye has set 8tk {$this->calledNumber}") . PHP_EOL;
        $res = $tempSocket->recvfrom($peer, 1);
        $tempSocket->close();


        if ($res) {
            $this->receiveBye = true;
            print cli::color("bold_red", "receiveBye has set 9 {$this->calledNumber}") . PHP_EOL;
        }
        $this->callActive = false;
        $this->socket->close();

        $this->__destruct();
        print cli::color("bold_red", "receiveBye has set 10 {$this->calledNumber}") . PHP_EOL;
    }

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
                "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
                "Contact" => [trunkController::renderURI([
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

    public function __destruct()
    {
        foreach ($this->socketsList as $socket) {
            try {
                if ($socket instanceof Socket) {
                    $socket->close();
                }
            } catch (Exception $e) {
                continue;
            }
        }
        $this->socket->close();
        $this->mediaSocket->close();
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
        Coroutine::create(function () use ($time) {
            while (true) {
                if ($this->callActive and !$this->receiveBye) break;
                Coroutine::sleep(0.1);
            }
            while (true) {
                Coroutine::sleep(0.1);
                if ($this->receiveBye) {
                    cli::pcl("BYE RECEIVED BEFORE TIMEOUT", 'bold_red');
                    break;
                }

                if (time() > $this->closeCallInTime) {
                    $this->callActive = false;


                    $this->receiveBye = true;
                    $this->socket->close();
                    $this->mediaSocket->close();

                    print cli::color('red', 'timeout reached....') . PHP_EOL;

                    $this->rtpSocket->close();
                    break;
                }
            }
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
        if ($this->registerCount > 3) {
            return false;
        }
        $res = false;
        $modelRegister = $this->modelRegister();
        $startTimer = time();
        $this->socket->sendto($this->host, $this->port, sip::renderSolution($modelRegister));
        for ($n = $this->connectTimeout; $n--;) {
            if (time() - $startTimer > $maxWait) {
                print cli::cl("red", "line 753 timeout");
                return false;
            }
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
                $respond = \handlers\renderMessages::respondOptions($receive["headers"]);
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

    public function sendDtmf(string $digit): bool
    {
        $this->dtmfList[] = $digit;
        return true;
    }

    public function saveBufferToWavFile(string $caminho, string $audioBuffer): void
    {
        $audioBuffer = $this->mixPcmArray([$audioBuffer]);
        $audio = waveHead(strlen($audioBuffer), 8000, 1, 1) . $audioBuffer;
        file_put_contents($caminho, $audio);
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
            usleep(1000000);
        } while (time() - $startTime < $timeout);
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
        foreach ($agents as $agent) {
            (function ($agent) {
                echo "✅ Transferindo chamada para {$agent}...\n";
                $this->transfer($agent);
            })($agent);
        }
        cli::pcl("Já saiu do loop");
    }

    public function resetTimeout(): void
    {
        $this->timeoutCall = time();
    }

    public function declareAudio(string $string, mixed $currentCodec, $newSound = false): void
    {
        $filePath = $string;
        $this->newSound = true;
        try {
            $trueFormat = phone::staticDetectFileFormatFromHeader($filePath);
        } catch (Exception $e) {
            cli::pcl("Erro ao detectar o formato do arquivo de áudio: {$filePath}", "red");
            $trueFormat = pathinfo($filePath, PATHINFO_EXTENSION);
        }
        if ($trueFormat !== pathinfo($filePath, PATHINFO_EXTENSION)) {
            $newFilePath = pathinfo($filePath, PATHINFO_DIRNAME) . '/' . pathinfo($filePath, PATHINFO_FILENAME) . '.' . $trueFormat;
            rename($filePath, $newFilePath);
            $filePath = $newFilePath;
        }
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new Exception("Erro: Arquivo de áudio inválido ou não pode ser lido: {$filePath}");
        }
        $file = fopen($filePath, 'rb');
        $header = fread($file, 44);
        $sampleRate = unpack('V', substr($header, 24, 4))[1];
        $channels = unpack('v', substr($header, 22, 2))[1];
        $bitsPerSample = unpack('v', substr($header, 34, 2))[1];
        if ($bitsPerSample !== 16 || $channels !== 1 || $sampleRate !== 8000) {
            try {
                cli::pcl("Arquivo de áudio não suportado: {$filePath} - usando phone::staticConvertToWav", "red");
                $filePath = phone::staticConvertToWav($filePath, 8000, 1, 16);
            } catch (Exception $e) {
            }
        }
        if (!$file) {
            throw new Exception("Erro ao abrir o arquivo WAV: {$filePath}");
        }
        $header = fread($file, 1240);
        fclose($file);
        if (empty($header)) {
            cli::pcl("Arquivo de áudio não suportado: {$filePath} - usando phone::staticConvertToWav", "white");
            try {
                $filePath = phone::staticConvertToWav($filePath);
            } catch (Exception $e) {
                cli::pcl("Erro ao converter o arquivo de áudio para WAV: {$filePath}", "red");
            }
        }
        cli::pcl("Novo audio declarado: {$filePath}");
        $this->audioFilePath = $filePath;
        $this->currentCodec = (int)$currentCodec;
        if ($this->currentCodec == 18) {
            $this->channel = new bcg729Channel();
        }
    }

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

    public function onReceiveAudio(Closure $param)
    {
        $this->onReceiveAudioCallback = $param;
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
            Coroutine::sleep(0.02);
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
}

function encodePcmToPcma(string $data): string
{
    if (strlen($data) % 2 !== 0) {
        $data .= "\x00";
    }

    $samples = unpack('s*', $data);
    $encoded = '';

    foreach ($samples as $sample) {
        $encoded .= chr(linear2alaw($sample));
    }

    return $encoded;
}

function encodePcmToPcmu(string $data): string
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