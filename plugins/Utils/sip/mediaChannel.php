<?php

namespace libspech\Rtp;

use bcg729Channel;
use Closure;
use libspech\Cli\cli;
use libspech\Sip\AudioQualityDetector;
use opusChannel;
use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

class MediaChannel
{
    public bool $active = true;

    public int $connectTimeout = 10;

    public function onReceive(callable $callback): void
    {
        $this->onReceiveCallable = $callback;
    }

    public function onDtmf(callable $callback): void
    {
        $this->onDtmfCallable = $callback;
    }

    public function onVadChange(callable $callback): void
    {
        $this->onVadChangeCallable = $callback;
    }

    /**
     * Habilita o sistema de adaptação automática
     */
    public function enableAdaptation(bool $useBuffer = true): void
    {
        $this->adaptationEnabled = true;
        if ($useBuffer) {
            $this->adaptiveBuffer->enable();
        }
    }

    /**
     * Desabilita o sistema de adaptação automática
     */
    public function disableAdaptation(): void
    {
        $this->adaptationEnabled = false;
        $this->adaptiveBuffer->disable();
    }

    public Socket $socket;


    /**
     * Array de membros com estrutura:
     * [
     *     'address' => string,
     *     'port' => int,
     *     'codec' => string,
     *     'pt' => int,
     *     'ssrc' => int,
     *     'timestamp' => int,
     *     'config' => array,
     *     'opus' => ?opusChannel,
     *     'frequency' => int,
     *     'rtpChannel' => rtpChannel
     * ]
     *
     * @var array<string, array{address: string, port: int, codec: string, pt: int, ssrc: int, timestamp: int, config: array, opus: ?opusChannel, frequency: int, rtpChannel: rtpChannel}>
     */
    public array $members = [];
    public int $defaultCodec = 8;
    public bcg729Channel $channelEncode;
    public bcg729Channel $channelDecode;
    public array $ptCodecs = [
        18 => 'G729', // G729
        101 => 'telephone-event', // DTMF
    ];
    public array $ptCodecsFrequency = [
        'G729' => 8000, // G729
        'telephone-event' => 8000, // DTMF
    ];
    public ?opusChannel $opusChannel = null;
    public string $callId;
    public array $codecMapper = [];
    private ?\Swoole\Coroutine\Channel $blockChannel = null;
    public $onReceiveCallable = null;
    public $onDtmfCallable = null;
    public $onVadChangeCallable = null;
    public $onRecordingCallable = null;
    public bool $vadEnabled = false;
    public bool $isVoiceActive = false;
    private float $vadThreshold = 2.0;
    private int $vadHangoverFrames = 20;
    private int $vadCurrentHangover = 0;
    public bool $recordingEnabled = false;
    private string $recordingPath = '';
    private array $dtmfLastEvent = [];
    private int $dtmfDebounceMs = 100;
    private array $dtmfPacketCache = []; // Cache para detectar retransmissões RFC 4733
    public array $audioMetrics = [
        'total_packets' => 0,
        'lost_packets' => 0,
        'avg_energy' => 0.0,
        'voice_time' => 0.0,
        'silence_time' => 0.0,
    ];
    private AudioQualityDetector $qualityDetector;
    private AdaptiveBuffer $adaptiveBuffer;
    private bool $adaptationEnabled = false;
    private array $qualityReports = [];
    private int $adaptationCheckInterval = 50;
    private int $packetsProcessed = 0;
    public array $registeredIds = [];
    private array $lastVadActivity = [];
    private int $vadTimeoutSeconds = 10;
    private float $vadRegistrationThreshold = 2.0;
    public array $rChannels = [];
    private array $timeoutHistory = [];
    private int $consecutiveTimeouts = 0;
    private float $adaptiveTimeoutBase = 2.0;
    private int $maxConsecutiveTimeouts = 5;
    private int $connectionHealthScore = 100;
    private array $packetLossWindow = [];
    private int $windowSize = 50;
    private float $lastValidPacketTime = 0;

    public function block($callback = null): void
    {
        if ($callback) {
            $callback($this);
        }
        $this->blockChannel->pop();
    }

    public function unblock(): void
    {
        $this->active = false;
        if ($this->blockChannel->length() === 0) {
            $this->blockChannel->push(true);
        }
        $this->blockChannel->close();
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

    public array $rtpChans = [];
    public Socket $eventSock;

    public function __construct(Socket $socket, string $callId)
    {

        $this->socket = $socket;
        $this->callId = $callId;
        $this->channelEncode = new bcg729Channel();
        $this->channelDecode = new bcg729Channel();
        $this->adaptiveBuffer = new AdaptiveBuffer($this->callId);
        $this->blockChannel = new \Swoole\Coroutine\Channel(1);


        $this->adaptationEnabled = true;
        $this->qualityReports = [];
        $this->adaptationCheckInterval = 50;
        $this->packetsProcessed = 0;
        $this->eventSock = new Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        $this->rtpChans = [];
    }

    public function resolveCodecNameFromPt(int $pt): ?string
    {
        if (isset($this->ptCodecs[$pt])) {
            return $this->ptCodecs[$pt];
        } elseif (in_array($pt, array_keys($this->codecMapper))) {
            return explode('/', $this->codecMapper[$pt])[0];
        } elseif ($pt === 0) {
            return "PCMU";
        } elseif ($pt === 1) {
            return "PCM";
        } elseif ($pt === 8) {
            return "PCMA";
        } elseif ($pt === 18) {
            return "G729";
        } elseif ($pt === 101) {
            return "telephone-event";
        }
        return "G729";
    }

    public function resolveFrequencyFromPt(int $pt): int
    {
        var_dump($this->ptCodecsFrequency, $this->ptCodecs);
        if (!empty($this->ptCodecsFrequency[$this->ptCodecs[$pt]])) {
            return $this->ptCodecsFrequency[$this->ptCodecs[$pt]];
        } elseif (in_array($pt, array_keys($this->codecMapper))) {
            return (int)explode('/', $this->codecMapper[$pt])[1] ?? 8000;
        } elseif ($pt === 0) {
            return 8000;
        } elseif ($pt === 1) {
            return 8000;
        } elseif ($pt === 8) {
            return 8000;
        }
        return 8000;
    }

    public function enableVAD(float $threshold = 2.0): void
    {
        $this->vadEnabled = true;
        $this->vadThreshold = $threshold;
    }

    /**
     * Define o valor mínimo de energia para registrar um ID pelo VAD
     */
    public function setVadRegistrationThreshold(float $threshold): void
    {
        $this->vadRegistrationThreshold = $threshold;
    }

    /**
     * Define o timeout em segundos para IDs registrados pelo VAD
     */
    public function setVadTimeout(int $timeoutSeconds): void
    {
        $this->vadTimeoutSeconds = $timeoutSeconds;
    }

    public array $openChannels = [];
    public int $portList = 0;
    public array $options = [];
    public int $retrys = 0;
    public mixed $ssrc = 0;

    public function generateDeterministicSsrc(string $ipPort): int
    {
        // Hash SHA-1 da string IP:porta (gera 40 caracteres hex)
        $hash = sha1($ipPort);

        // Pegar os primeiros 8 caracteres hex (32 bits)
        $hex = substr($hash, 0, 8);

        // Converter para inteiro (0 a 0xFFFFFFFF)
        $ssrc = (int)hexdec($hex);

        // Garantir que está dentro do range de 32 bits
        return $ssrc & 0xFFFFFFFF;
    }


    public function start(): void
    {
        Coroutine::create(function () {


            $maxFrequency = 8000;
            $this->active = true;

            // Rastreamento de SSRC e timestamp por destino
            $destinationChannels = []; // [targetId => ['ssrc' => int, 'timestamp' => int, 'lastFrequency' => int, 'sequenceNumber' => int]]

            foreach ($this->ptCodecsFrequency as $codec => $frequency) {
                if ($frequency > $maxFrequency) {
                    $maxFrequency = $frequency;
                }
            }

            // Calcula o incremento de timestamp baseado na frequência e tamanho do pacote (20ms padrão)
            $calculateTimestampIncrement = function (int $frequency, int $payloadType): int {
                // Para G729 (PT 18): 10 bytes = 10ms de áudio
                if ($payloadType === 18) {
                    return (int)($frequency * 0.01); // 10ms
                }
                // Para outros codecs: assumir 20ms
                return (int)($frequency * 0.02); // 20ms
            };

            $lastPacketTime = microtime(true);
            while (true) {


                $packet = $this->socket->recvfrom($peer, 0.2);


                $currentTime = microtime(true);
                if (!$packet) {
                    // timeout de 3s

                    if ($currentTime - $lastPacketTime > $this->connectTimeout) {

                        // encerrar
                        $this->unblock();
                        $this->socket->close();
                        $this->eventSock->close();
                        if (is_callable($this->packetOnTimeoutCallable)) {
                            go($this->packetOnTimeoutCallable, $this->callId);
                        }
                        cli::pcl("TIMEOUT: no packets received for {$this->connectTimeout} seconds", 'bold_red');
                        return;

                    }


                    $expectedMember = false;
                    $expectedMember = array_key_first($this->members) ?? false;
                    if (!$expectedMember) {
                        cli::pcl("TIMEOUT: no members to send silence to", 'bold_red');
                        continue;
                    }
                    $buffer = $this->members[$expectedMember]['rtpChannel']->buildAudioPacket(str_repeat("\x00", 160));
                    $this->socket->sendto($expectedMember, $this->members[$expectedMember]['port'], $buffer);
                    $packet = $this->socket->recvfrom($peer, 1);
                    if (!$packet) {
                        $this->unblock();
                        $this->socket->close();
                        $this->eventSock->close();
                        if (is_callable($this->packetOnTimeoutCallable)) {
                            go($this->packetOnTimeoutCallable, $this->callId);
                        }
                        cli::pcl("TIMEOUT: no packets received for {$this->connectTimeout} seconds", 'bold_red');
                        return;
                    }

                    cli::pcl("TIMEOUT: sending silence to {$expectedMember}", 'bold_red');

                }


                $this->packetsProcessed++;
                $lastPacketTime = microtime(true);
                $idFrom = "{$peer['address']}:{$peer['port']}";
                $this->audioMetrics['total_packets']++;

                $rtpc = new rtpc($packet);
                $pt = $rtpc->getCodec();
                $ssrc = $this->generateDeterministicSsrc($idFrom . $pt);

                if (!array_key_exists($rtpc->getCodec(), $this->ptCodecs)) {
                    $member = $this->members[$idFrom] ?? null;
                    if ($member) {
                        $this->ptCodecs[$rtpc->getCodec()] = $member['codec'] ?? $this->defaultCodec;
                    }
                }

                $codec = $this->resolveCodecNameFromPt($pt) ?? $pt;

                if (!array_key_exists($ssrc, $this->rtpChans)) {
                    $this->rtpChans[$ssrc] = new rtpChannel($rtpc->getCodec(), $this->ptCodecsFrequency[$codec] ?? 8000, 20, $ssrc);
                    $this->rtpChans[$ssrc]->sequenceNumber = $rtpc->sequence++;
                    $this->rtpChans[$ssrc]->timestamp = $rtpc->timestamp;
                    $this->rtpChans[$ssrc]->bcg729Channel = new bcg729Channel();
                }

                if (!$this->isMember($idFrom)) {
                    $this->addMember([
                        'address' => $peer['address'],
                        'port' => $peer['port'],
                        'codec' => $codec,
                        'pt' => $pt,
                        'ssrc' => $ssrc,
                        'timestamp' => $rtpc->timestamp,
                        'config' => $this->options['config'] ?? [],
                        'opus' => $this->members[$idFrom]['opus'] ?? null,

                        'frequency' => $this->resolveFrequencyFromPt($rtpc->getCodec()) ?? 8000,
                    ]);
                }

                $this->members[$idFrom]['ssrc'] = $ssrc;
                $pcmData = false;


                if (is_callable($this->onReceiveCallable)) go($this->onReceiveCallable, $rtpc, $peer, $this, $this->rtpChans[$ssrc]);

                if (!array_key_exists($rtpc->getCodec(), $this->ptCodecs)) {
                    $member = $this->members[$idFrom] ?? null;
                    if ($member) {
                        $this->ptCodecs[$rtpc->getCodec()] = $member['codec'] ?? $this->defaultCodec;
                    }
                }

                $pt = $rtpc->getCodec();

                if (strtolower($codec) === 'telephone-event') {
                    // Fazer forward dos pacotes DTMF para todos os membros
                    $this->forwardDtmfToMembers($rtpc, $peer, $idFrom, $destinationChannels);

                    // Processar o evento DTMF (detectar digit, callbacks, etc)
                    // O callback onDtmfCallable será disparado apenas 1x quando o evento terminar
                    $this->processDtmf($rtpc, $peer, function () {
                        // Callback vazio - o forward já foi feito acima
                    });

                    continue;
                }

                foreach ($this->members as $targetId => $info) {
                    if ($targetId === $idFrom) continue;

                    // Inicializar canal do destino se não existir
                    if (!isset($destinationChannels[$targetId])) {
                        $destSsrc = $this->generateDeterministicSsrc($targetId);
                        $destinationChannels[$targetId] = [
                            'ssrc' => $destSsrc,
                            'timestamp' => 0,
                            'lastFrequency' => $info['frequency'] ?? 8000,
                            'sequenceNumber' => 0,
                            'rtpChannel' => new rtpChannel($info['pt'], $info['frequency'] ?? 8000, 20, $destSsrc)
                        ];
                    }

                    $destChannel = &$destinationChannels[$targetId];
                    $currentFrequency = $info['frequency'] ?? 8000;

                    // Detectar mudança de frequência e ajustar timestamp
                    if ($destChannel['lastFrequency'] !== $currentFrequency) {
                        // Converter timestamp para a nova frequência proporcionalmente
                        if ($destChannel['timestamp'] > 0) {
                            $ratio = $currentFrequency / $destChannel['lastFrequency'];
                            $destChannel['timestamp'] = (int)($destChannel['timestamp'] * $ratio);
                        }
                        $destChannel['lastFrequency'] = $currentFrequency;
                    }

                    $frequencyPacket = (int)($this->members[$idFrom]['frequency'] ?? $this->ptCodecsFrequency[$info['codec']] ?? 8000);

                    // if (!$pcmData) {
                    $pcmData = match (strtoupper($codec)) {
                        'G729' => $this->rtpChans[$ssrc]->bcg729Channel->decode($rtpc->payloadRaw),
                        'PCMU' => decodePcmuToPcm($rtpc->payloadRaw),
                        'PCMA' => decodePcmaToPcm($rtpc->payloadRaw),
                        'OPUS' => $this->members[$targetId]['opus']->decode($rtpc->payloadRaw),
                        'L16' => pcmLeToBe($rtpc->payloadRaw),
                        default => $rtpc->payloadRaw,
                    };
                    //  }

                    $encode = null;
                    $frequencyMember = $currentFrequency;

                    switch (strtoupper($info['codec'])) {
                        case 'PCMU':
                            if ($frequencyPacket !== 8000) $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                            $encode = encodePcmToPcmu($pcmData);
                            break;
                        case 'PCMA':
                            if ($frequencyPacket !== 8000) $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                            $encode = encodePcmToPcma($pcmData);
                            break;
                        case 'G729':
                            if ($frequencyPacket !== 8000) $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                            $encode = $this->channelEncode->encode($pcmData);
                            break;
                        case 'OPUS':
                            $pcm48_mono = $this->members[$targetId]['opus']->resample($pcmData, $frequencyPacket, 48000);
                            $encode = $this->members[$targetId]['opus']->encode($pcm48_mono, 48000);
                            break;
                        case 'L16':
                            $encode = resampler($pcmData, $frequencyPacket, $frequencyMember, true);
                            break;
                        default:
                            $encode = $rtpc->payloadRaw;
                            break;
                    }

                    // Calcular incremento de timestamp baseado na frequência e tipo de payload
                    $timestampIncrement = $calculateTimestampIncrement($currentFrequency, $info['pt']);

                    // Incrementar timestamp do destino
                    if ($destChannel['timestamp'] === 0) {
                        $destChannel['timestamp'] = rand(0, 0xFFFFFFFF); // Timestamp inicial aleatório
                    } else {
                        $destChannel['timestamp'] += $timestampIncrement;
                    }

                    // Garantir que timestamp está dentro do range de 32 bits
                    $destChannel['timestamp'] = $destChannel['timestamp'] & 0xFFFFFFFF;

                    // Usar o canal RTP específico do destino com SSRC consistente
                    $destChannel['rtpChannel']->setPayloadType($info['pt']);
                    $destChannel['rtpChannel']->setFrequency($currentFrequency);
                    $destChannel['rtpChannel']->setSsrc($destChannel['ssrc']);

                    $newPacket = $destChannel['rtpChannel']->buildAudioPacket($encode);
                    $this->socket->sendto($info['address'], $info['port'], $newPacket);

                    if ($pcmData !== false) {
                        $this->processVAD($pcmData, $idFrom);
                    }
                }
            }
        });
    }

    public function isMember(string $id): bool
    {
        return isset($this->members[$id]);
    }

    public function addMember(array $peer): void
    {
        $opus = new opusChannel(48000, 1);


        $peer['opus'] = $opus;


        $rate = $peer['frequency'];
        $id = "{$peer['address']}:{$peer['port']}";

        if (!empty($peer['config'])) {
            if (!empty($peer['config'][(int)$peer['pt']])) {
                if (!empty($peer['config']['maxaveragebitrate'])) {
                    $rate = 'Max. Average Bitrate: ' . $peer['config']['maxaveragebitrate'] . ' ';
                    $opus->setBitrate((int)$peer['config']['maxaveragebitrate']);
                } elseif (!empty($peer['config']['maxplaybackrate'])) {
                    $rate = 'Max. Playback Rate: ' . $peer['config']['maxplaybackrate'] . ' ';
                    $opus->setBitrate((int)$peer['config']['maxplaybackrate']);
                } else $opus->setBitrate(64000);


                $config = $peer['config'];
                if (!empty($config['userdtx'])) $opus->setDTX(true);
                if (!empty($config['cbr'])) $opus->setVBR(true);
                $opus->setComplexity(8);
                $opus->setSignalVoice(true);


            }
        }
        print cli::cl('bold_green', $rate . " " . $id . " MEMBER ADDED IN CALL " . $peer['codec'] . ' PT ' . $peer['pt'] . ' ' . $peer['frequency'] . ' kHz');
        // criar rtpChannel
        $peer['rtpChannel'] = new rtpChannel((int)$peer['pt'], $peer['frequency'], 20, $this->generateDeterministicSsrc($id));
        $peer['rtpChannel']->setSsrc($this->generateDeterministicSsrc($id));


        $this->members[$id] = $peer;

    }

    private function processVAD(string $pcmData, ...$extra): void
    {
        if (!$this->vadEnabled) {
            return;
        }
        $energy = volumeAverage($pcmData);
        $this->audioMetrics['avg_energy'] = $this->audioMetrics['avg_energy'] * 0.9 + $energy * 0.1;
        $wasActive = $this->isVoiceActive;
        $idFrom = $extra[0] ?? $this->callId;
        if ($energy > $this->vadRegistrationThreshold) {
            if (!isset($this->registeredIds[$idFrom])) {
                $this->registeredIds[$idFrom] = true;
            }
            $this->lastVadActivity[$idFrom] = microtime(true);
        }
        if ($energy > $this->vadThreshold) {
            $this->isVoiceActive = true;
            $this->vadCurrentHangover = $this->vadHangoverFrames;
        } else if ($this->vadCurrentHangover > 0) {
            $this->vadCurrentHangover--;
            $this->isVoiceActive = true;
        } else {
            $this->isVoiceActive = false;
        }
        if ($wasActive !== $this->isVoiceActive) {
            if (is_callable($this->onVadChangeCallable)) {
                go($this->onVadChangeCallable, $this->isVoiceActive, $energy, $extra[0]);
            }
        }
        if ($this->isVoiceActive) {
            $this->audioMetrics['voice_time'] += 0.02;
        } else {
            $this->audioMetrics['silence_time'] += 0.02;
        }
    }

    private function checkVadTimeouts(): bool
    {
        $currentTime = microtime(true);
        foreach ($this->registeredIds as $idFrom => $registered) {
            if (!$registered) {
                continue;
            }
            $lastActivity = $this->lastVadActivity[$idFrom] ?? 0;
            if ($currentTime - $lastActivity > $this->vadTimeoutSeconds) {
                return true;
            }
        }
        return false;
    }

    public function close(): void
    {
        $this->active = false;
        // Fecha o socket primeiro
        try {
            if (!$this->socket->isClosed()) {
                $this->socket->close();
            }
        } catch (\Throwable $e) {
        }

        try {
            if (!$this->eventSock->isClosed()) {
                $this->eventSock->close();
            }
        } catch (\Throwable $e) {
        }

        // Limpa o blockChannel
        if ($this->blockChannel) {
            try {
                // Consumir dados pendentes
                while (!$this->blockChannel->isEmpty()) {
                    $this->blockChannel->pop(0.001);
                }
                // Fechar o channel
                $this->blockChannel->close();
            } catch (\Throwable $e) {
            }
        }

        // Limpa os canais opus
        foreach ($this->openChannels as $channel) {
            try {
                if (is_a($channel, opusChannel::class)) {
                    if (method_exists($channel, 'destroy')) {
                        $channel->destroy();
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        // Limpa os rtpChans
        foreach ($this->rtpChans as $channel) {
            try {
                if (is_a($channel, rtpChannel::class)) {
                    if (property_exists($channel, 'bcg729Channel')) {
                        unset($channel->bcg729Channel);
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        // Limpa os membros
        foreach ($this->members as $id => $member) {
            try {
                if (isset($member['opus']) && is_a($member['opus'], opusChannel::class)) {
                    if (method_exists($member['opus'], 'destroy')) {
                        $member['opus']->destroy();
                    }
                }
                if (isset($member['rtpChannel'])) {
                    unset($member['rtpChannel']);
                }
            } catch (\Throwable $e) {
            }
        }

        // Limpa arrays
        $this->members = [];
        $this->rtpChans = [];
        $this->openChannels = [];

        cli::pcl("MediaChannel fechado Call-ID: {$this->callId}", 'green');
    }

    /**
     * Faz forward de pacotes DTMF (telephone-event) para todos os membros
     * Mantém o timestamp original do evento DTMF e ajusta o PT conforme necessário
     *
     * @param rtpc $rtpc Pacote RTP original com evento DTMF
     * @param array $peer Informações do peer de origem ['address' => string, 'port' => int]
     * @param string $idFrom Identificador do membro de origem (address:port)
     * @param array $destinationChannels Array de canais RTP por destino (passado por referência)
     */
    private function forwardDtmfToMembers(rtpc $rtpc, array $peer, string $idFrom, array &$destinationChannels): void
    {
        foreach ($this->members as $targetId => $info) {
            // Não enviar para si mesmo
            if ($targetId === $idFrom) {
                continue;
            }

            // Não reenviar para o SSRC de origem
            if (array_key_exists('ssrc', $info) && $rtpc->ssrc == $info['ssrc']) {
                continue;
            }

            // Inicializar canal do destino se não existir
            if (!isset($destinationChannels[$targetId])) {
                $destSsrc = $this->generateDeterministicSsrc($targetId);
                $destinationChannels[$targetId] = [
                    'ssrc' => $destSsrc,
                    'timestamp' => 0,
                    'lastFrequency' => $info['frequency'] ?? 8000,
                    'sequenceNumber' => 0,
                    'rtpChannel' => new rtpChannel($info['pt'], $info['frequency'] ?? 8000, 20, $destSsrc)
                ];
            }

            $destChannel = &$destinationChannels[$targetId];
            $frequencyMember = $this->ptCodecsFrequency[$info['codec']] ?? 8000;

            // Encontrar o PT correto do telephone-event para este destino
            $telephoneEventPt = $this->findTelephoneEventPt($frequencyMember);

            // Configurar o PT do telephone-event no canal de destino
            $destChannel['rtpChannel']->setNewPtDTMF($telephoneEventPt);

            // Detectar se é o primeiro pacote do evento (marker bit)
            $isFirstPacket = ($rtpc->marker === 1);


            // Construir e enviar pacote DTMF preservando timestamp original
            $outPacket = $destChannel['rtpChannel']->buildDtmfForwardPacket(
                $rtpc->payloadRaw,
                $rtpc->timestamp,
                $isFirstPacket
            );

            $this->socket->sendto($info['address'], $info['port'], $outPacket);
        }
    }

    /**
     * Encontra o PT correto do telephone-event para uma frequência específica
     * Busca primeiro na configuração registrada, depois usa fallback
     *
     * @param int $frequency Frequência do codec de áudio (8000, 16000, 48000, etc)
     * @return int PT do telephone-event (geralmente 101)
     */
    private function findTelephoneEventPt(int $frequency): int
    {
        // Buscar nos codecs registrados com a frequência exata
        $targetKey = 'telephone-event_' . $frequency;
        if (isset($this->ptCodecsFrequency[$targetKey])) {
            // Encontrar o PT correspondente
            foreach ($this->ptCodecs as $pt => $codecName) {
                if (strtolower($codecName) === 'telephone-event') {
                    // Verificar se existe a chave composta para esta frequência
                    if (isset($this->ptCodecsFrequency['telephone-event_' . $frequency])) {
                        return $pt;
                    }
                }
            }
        }

        // Fallback: buscar telephone-event sem verificar frequência
        foreach ($this->ptCodecs as $pt => $codecName) {
            if (strtolower($codecName) === 'telephone-event') {
                return $pt;
            }
        }

        // Fallback final: PT 101 (padrão RFC 4733)
        return 101;
    }


    public function registerPtCodecs(array $ptCodecs): void
    {
        $preRender = [
            0 => 'PCMU/8000',
            8 => 'PCMA/8000',
            18 => 'G729/8000',
            101 => 'telephone-event/8000',
        ];

        // Registrar codecs padrão primeiro
        foreach ($preRender as $pt => $codec) {
            $parts = explode('/', $codec);
            $codecName = $parts[0] ?? $pt;
            $frequency = (int)($parts[1] ?? 8000);

            $this->ptCodecs[$pt] = $codecName;

            // Para telephone-event, usar chave composta para suportar múltiplas frequências
            if (strtolower($codecName) === 'telephone-event') {
                $this->ptCodecsFrequency[$codecName . '_' . $frequency] = $frequency;
            } else {
                $this->ptCodecsFrequency[$codecName] = $frequency;
            }
        }

        // Sobrescrever com codecs fornecidos pelo SDP
        foreach ($ptCodecs as $pt => $codec) {
            $parts = explode('/', $codec);
            $codecName = $parts[0] ?? $pt;
            $frequency = (int)($parts[1] ?? 8000);

            $this->ptCodecs[$pt] = $codecName;

            // Para telephone-event, usar chave composta para suportar múltiplas frequências
            if (strtolower($codecName) === 'telephone-event') {
                $this->ptCodecsFrequency[$codecName . '_' . $frequency] = $frequency;
            } else {
                $this->ptCodecsFrequency[$codecName] = $frequency;
            }
        }
    }

    public function packetOnTimeout(callable $param): void
    {
        $this->packetOnTimeoutCallable = $param;
    }

    public mixed $packetOnTimeoutCallable = false;

    public function getFrequencyFromPtCodec(int $pt)
    {
        if (isset($this->ptCodecsFrequency[$this->ptCodecs[$pt]])) {
            return $this->ptCodecsFrequency[$this->ptCodecs[$pt]];
        }
        return 8000;
    }


    /**
     * Processa eventos telephone-event (RFC 4733/2833)
     * Implementa tratamento correto de DTMF com:
     * - Detecção de retransmissões (RFC requer 3 pacotes finais)
     * - Validação de flag E (End)
     * - Debouncing baseado em timestamp + sequence
     * - Forward correto para outros membros
     */
    private function processDtmf(rtpc $rtpc, mixed $peer, Closure $closure): void
    {
        Coroutine::create(function () use ($rtpc, $peer, $closure) {
            $ssrc = $rtpc->ssrc;
            $payload = $rtpc->payloadRaw;
            $sequence = $rtpc->sequence;
            $timestamp = $rtpc->timestamp;

            // RFC 4733: payload mínimo = 4 bytes
            if (strlen($payload) < 4) {
                return;
            }

            // RFC 4733 Section 2.3: Event Payload Format
            // 0                   1                   2                   3
            // 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1 2 3 4 5 6 7 8 9 0 1
            // +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
            // |     event     |E|R| volume    |          duration             |
            // +-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+-+
            $data = unpack('Cevent/Cflags/nduration', $payload);
            $event = $data['event'];
            $flags = $data['flags'];
            $isEnd = ($flags & 0x80) !== 0; // Flag E (End of Event)
            $volume = $flags & 0x3F; // 6 bits de volume
            $duration = $data['duration'];


            if ($duration < 200) {
                $callback = $this->onDtmfCallable;
                if (is_callable($callback)) {
                    go($callback, $event, $peer);
                }
            }


            // Criar chave única para este evento específico
            $cacheKey = "{$ssrc}:{$timestamp}:{$event}";

            // RFC 4733 Section 2.5.1.4: Detectar retransmissões
            // O timestamp permanece o mesmo durante todo o evento
            // Sequence number incrementa a cada pacote
            if (isset($this->dtmfPacketCache[$cacheKey])) {
                $cached = $this->dtmfPacketCache[$cacheKey];

                // Se já processamos um pacote END para este evento, ignorar retransmissões
                if ($cached['processed'] && $isEnd) {
                    // RFC: últimos 3 pacotes são idênticos com flag E set
                    $closure(); // Fazer forward do pacote mesmo sendo duplicado
                    return;
                }

                // Atualizar informações do evento em andamento
                $this->dtmfPacketCache[$cacheKey]['sequence'] = $sequence;
                $this->dtmfPacketCache[$cacheKey]['duration'] = $duration;
                $this->dtmfPacketCache[$cacheKey]['lastSeen'] = microtime(true);
            } else {
                // Novo evento DTMF iniciado
                $this->dtmfPacketCache[$cacheKey] = [
                    'event' => $event,
                    'timestamp' => $timestamp,
                    'sequence' => $sequence,
                    'duration' => $duration,
                    'volume' => $volume,
                    'firstSeen' => microtime(true),
                    'lastSeen' => microtime(true),
                    'processed' => false,
                ];
            }


            // Forward para outros membros (sempre, para manter sincronização)
            $closure();

            // Processar apenas quando flag E (End) está setada
            if (!$isEnd) {

                return;
            }

            // Verificar se já processamos este evento
            if ($this->dtmfPacketCache[$cacheKey]['processed']) {

                return;
            }

            // Validação de debounce entre eventos diferentes
            // RFC 4733: eventos diferentes devem ter timestamps diferentes
            if (isset($this->dtmfLastEvent[$ssrc])) {
                $last = $this->dtmfLastEvent[$ssrc];

                // Se for o mesmo evento com timestamp muito próximo, ignorar
                if ($last['event'] === $event && $timestamp === $last['timestamp']) {
                    return;
                }

                // Debounce adicional: mínimo 50ms entre eventos (400 samples @ 8kHz)
                $timeDiffSamples = abs($timestamp - $last['timestamp']);
                $timeDiffMs = ($timeDiffSamples / 8); // 8000Hz = 8 samples/ms

                if ($timeDiffMs < 50 && $last['event'] === $event) {
                    return;
                }
            }

            // Marcar como processado
            $this->dtmfPacketCache[$cacheKey]['processed'] = true;

            // Atualizar último evento processado
            $this->dtmfLastEvent[$ssrc] = [
                'event' => $event,
                'timestamp' => $timestamp,
                'sequence' => $sequence,
                'duration' => $duration,
                'time' => microtime(true),
            ];

            // Traduzir evento para dígito
            $digit = $this->translateDigit($event);


            // Ajustar timestamps dos membros para compensar duração do DTMF
            // RFC 4733: duration está em unidades de timestamp (samples)
            foreach ($this->members as $idTarget => $info) {
                if ($idTarget == "{$peer['address']}:{$peer['port']}") continue;
                if (array_key_exists('ssrc', $info) && $info['ssrc'] == $rtpc->ssrc) {
                    continue;
                }

                // Incrementar timestamp baseado na duração real do evento
                // Usar duration do pacote ao invés de valor fixo
                if (isset($this->members[$idTarget]['timestamp'])) {
                    $this->members[$idTarget]['timestamp'] += $duration;
                }
            }

            // Disparar callback de DTMF

            // Limpar cache antigo (> 5 segundos)
            $currentTime = microtime(true);
            foreach ($this->dtmfPacketCache as $key => $cache) {
                if ($currentTime - $cache['lastSeen'] > 5.0) {
                    unset($this->dtmfPacketCache[$key]);
                }
            }
        });
    }

    private function translateDigit(mixed $event): string
    {
        $mapa = [
            0 => '0',
            1 => '1',
            2 => '2',
            3 => '3',
            4 => '4',
            5 => '5',
            6 => '6',
            7 => '7',
            8 => '8',
            9 => '9',
            10 => '*',
            11 => '#',
        ];
        return $mapa[$event] ?? '';
    }
}