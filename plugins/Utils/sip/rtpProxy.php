<?php

use Plugin\Utils\cli;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;

/**
 * RTP Proxy Implementation - RFC 3550 compliant
 *
 * Este proxy implementa roteamento de pacotes RTP entre múltiplos endpoints,
 * com suporte para transcodificação de codecs, DTMF (RFC 4733) e VAD.
 *
 * @see https://tools.ietf.org/html/rfc3550 - RTP: A Transport Protocol for Real-Time Applications
 * @see https://tools.ietf.org/html/rfc4733 - RTP Payload for DTMF Digits, Telephony Tones and Events
 * @see https://tools.ietf.org/html/rfc3551 - RTP Profile for Audio and Video Conferences
 */
class RtpProxy
{
    /** @var Socket Socket UDP para recepção/envio de pacotes RTP */
    private Socket $socket;

    /** @var string ID único da sessão/chamada */
    private string $sessionId;

    /** @var array<string, RtpEndpoint> Endpoints conectados ao proxy */
    private array $endpoints = [];

    /** @var Channel Canal para sincronização de bloqueio */
    private Channel $blockChannel;

    /** @var bool Flag indicando se o proxy está ativo */
    private bool $isActive = false;

    /** @var int Timeout em segundos para inatividade */
    private int $inactivityTimeout = 30;

    /** @var float Timestamp do último pacote recebido */
    private float $lastPacketTime;

    /** @var RtpStatistics Estatísticas da sessão */
    private RtpStatistics $statistics;

    /** @var DtmfProcessor Processador de eventos DTMF */
    private DtmfProcessor $dtmfProcessor;

    /** @var VadProcessor Processador de VAD (Voice Activity Detection) */
    private ?VadProcessor $vadProcessor = null;

    /** @var array Callbacks registrados */
    private array $callbacks = [
        'onReceive' => null,
        'onDtmf' => null,
        'onVadChange' => null,
        'onTimeout' => null,
        'onError' => null,
    ];

    /** @var array Canais de destino únicos por par source->target */
    private array $destinationChannels = [];

    /**
     * Construtor do RTP Proxy
     *
     * @param Socket $socket Socket UDP já configurado
     * @param string $sessionId ID único da sessão
     */
    public function __construct(Socket $socket, string $sessionId)
    {
        $this->socket = $socket;
        $this->sessionId = $sessionId;
        $this->blockChannel = new Channel(1);
        $this->statistics = new RtpStatistics();
        $this->dtmfProcessor = new DtmfProcessor();
        $this->lastPacketTime = microtime(true);
    }

    /**
     * Adiciona um endpoint ao proxy
     * RFC 3550 Section 3: RTP Session Membership
     *
     * @param string $address Endereço IP do endpoint
     * @param int $port Porta UDP do endpoint
     * @param array $config Configuração do endpoint (codec, frequência, etc)
     */
    public function addEndpoint(string $address, int $port, array $config = []): void
    {
        $endpointId = "{$address}:{$port}";

        if (isset($this->endpoints[$endpointId])) {
            cli::pcl("[RTP] Endpoint {$endpointId} já existe, atualizando configuração", 'yellow');
        }

        $this->endpoints[$endpointId] = new RtpEndpoint(
            $address,
            $port,
            $config['codec'] ?? 'PCMU',
            $config['payloadType'] ?? 0,
            $config['frequency'] ?? 8000,
            $config['ssrc'] ?? $this->generateSsrc($endpointId)
        );

        cli::pcl("[RTP] Endpoint {$endpointId} adicionado - Codec: {$config['codec']}, PT: {$config['payloadType']}", 'green');
    }

    /**
     * Remove um endpoint do proxy
     *
     * @param string $address Endereço IP do endpoint
     * @param int $port Porta UDP do endpoint
     */
    public function removeEndpoint(string $address, int $port): void
    {
        $endpointId = "{$address}:{$port}";

        if (isset($this->endpoints[$endpointId])) {
            unset($this->endpoints[$endpointId]);
            cli::pcl("[RTP] Endpoint {$endpointId} removido", 'yellow');
        }
    }

    /**
     * Inicia o processamento do proxy
     * RFC 3550 Section 6: RTP Control Protocol
     */
    public function start(): void
    {
        if ($this->isActive) {
            cli::pcl("[RTP] Proxy já está ativo", 'yellow');
            return;
        }

        $this->isActive = true;

        Coroutine::create(function () {
            cli::pcl("[RTP] Proxy iniciado para sessão {$this->sessionId}", 'green');

            while ($this->isActive) {
                // Recebe pacote com timeout de 200ms
                $packet = $this->socket->recvfrom($peer, 0.2);

                if ($packet === false) {
                    // Verifica timeout de inatividade
                    if ($this->checkInactivityTimeout()) {
                        $this->handleTimeout();
                        break;
                    }
                    continue;
                }

                // Processa o pacote recebido
                $this->processPacket($packet, $peer);
            }

            cli::pcl("[RTP] Proxy finalizado para sessão {$this->sessionId}", 'red');
            $this->unblock();
        });
    }

    /**
     * Processa um pacote RTP recebido
     * RFC 3550 Section 5: RTP Data Transfer Protocol
     *
     * @param string $packet Dados do pacote RTP
     * @param array $peer Informações do peer ['address' => string, 'port' => int]
     */
    private function processPacket(string $packet, array $peer): void
    {
        $this->lastPacketTime = microtime(true);
        $endpointId = "{$peer['address']}:{$peer['port']}";

        // Atualiza estatísticas
        $this->statistics->incrementPackets();

        // Parse do pacote RTP
        $rtpPacket = new RtpPacket($packet);

        // Adiciona endpoint dinamicamente se não existir
        if (!isset($this->endpoints[$endpointId])) {
            $this->addEndpoint($peer['address'], $peer['port'], [
                'payloadType' => $rtpPacket->getPayloadType(),
            ]);
        }

        $sourceEndpoint = $this->endpoints[$endpointId];
        $sourceEndpoint->updateActivity();

        // Callback onReceive
        if (is_callable($this->callbacks['onReceive'])) {
            Coroutine::create($this->callbacks['onReceive'], $rtpPacket, $peer, $this);
        }

        // Verifica se é DTMF (RFC 4733)
        if ($this->isDtmfPayload($rtpPacket->getPayloadType())) {
            $this->processDtmfPacket($rtpPacket, $peer);
            return;
        }

        // Faz forward do pacote para outros endpoints
        $this->forwardPacket($rtpPacket, $sourceEndpoint);

        // Processa VAD se habilitado
        if ($this->vadProcessor !== null) {
            $this->processVad($rtpPacket, $endpointId);
        }
    }

    /**
     * Faz forward de um pacote para outros endpoints
     * Cada par source->target tem seus próprios canais de codec (estado isolado)
     * RFC 3550 Section 7: Translators and Mixers
     *
     * @param RtpPacket $packet Pacote RTP a ser encaminhado
     * @param RtpEndpoint $source Endpoint de origem
     */
    private function forwardPacket(RtpPacket $packet, RtpEndpoint $source): void
    {
        // Cache de PCM decodificado (decode acontece apenas 1x por source)
        $pcmCache = null;
        $sourceCodec = strtoupper($source->getCodec());
        $sourceFreq = $source->getFrequency();

        // Obter ID do source para criar chaves únicas
        $sourceId = "{$source->getAddress()}:{$source->getPort()}";

        foreach ($this->endpoints as $targetId => $target) {
            // Não envia para o próprio remetente
            if ($target === $source) {
                continue;
            }

            $targetCodec = strtoupper($target->getCodec());
            $targetFreq = $target->getFrequency();

            // Criar chave única para o par source->target
            $channelKey = "{$sourceId}->{$targetId}";

            // Inicializar canal de destino se não existir (ÚNICO por par source->target)
            if (!isset($this->destinationChannels[$channelKey])) {
                $this->destinationChannels[$channelKey] = [
                    'ssrc' => $target->getSsrc(),
                    'timestamp' => rand(0, 0xFFFFFFFF),
                    'sequenceNumber' => 0,
                    'rtpChannel' => new rtpChannel($target->getPayloadType(), $targetFreq, 20, $target->getSsrc()),
                    // Canais de codec ÚNICOS para este par source->target
                    'bcg729Decode' => new bcg729Channel(),
                    'bcg729Encode' => new bcg729Channel(),
                    'opusEncode' => new opusChannel(48000, 1),
                    'opusDecode' => new opusChannel(48000, 1),
                ];
            }

            $destChannel = &$this->destinationChannels[$channelKey];

            // Decodificar PCM (seguindo lógica do mediaChannel.php original)
            if ($pcmCache === null) {
                $pcmCache = match ($sourceCodec) {
                    'PCMU' => decodePcmuToPcm($packet->getPayload()),
                    'PCMA' => decodePcmaToPcm($packet->getPayload()),
                    'G729' => function() use ($packet, &$destChannel) {
                        // G729 usa canal dedicado do par source->target para decode
                        return $destChannel['bcg729Decode']->decode($packet->getPayload());
                    },
                    'OPUS' => function() use ($packet, &$destChannel) {
                        // OPUS: replicando lógica do original (usa canal do target)
                        // Linha 460 do mediaChannel.php: $this->members[$targetId]['opus']->decode()
                        return $destChannel['opusDecode']->decode($packet->getPayload());
                    },
                    'L16' => pcmLeToBe($packet->getPayload()),
                    default => $packet->getPayload(),
                };

                // Executar decode se for função
                if (is_callable($pcmCache)) {
                    $pcmCache = $pcmCache();
                }
            }

            // Resampling e encoding com canais DEDICADOS ao par source->target
            $encodedData = match ($targetCodec) {
                'PCMU' => function() use ($pcmCache, $sourceFreq) {
                    $pcm = ($sourceFreq !== 8000) ? resampler($pcmCache, $sourceFreq, 8000) : $pcmCache;
                    return encodePcmToPcmu($pcm);
                },
                'PCMA' => function() use ($pcmCache, $sourceFreq) {
                    $pcm = ($sourceFreq !== 8000) ? resampler($pcmCache, $sourceFreq, 8000) : $pcmCache;
                    return encodePcmToPcma($pcm);
                },
                'G729' => function() use ($pcmCache, $sourceFreq, &$destChannel) {
                    $pcm = ($sourceFreq !== 8000) ? resampler($pcmCache, $sourceFreq, 8000) : $pcmCache;
                    // Usando canal DEDICADO deste par source->target
                    return $destChannel['bcg729Encode']->encode($pcm);
                },
                'OPUS' => function() use ($pcmCache, $sourceFreq, &$destChannel) {
                    // OPUS usa seu próprio resample interno (não usar resampler() global)
                    $pcm48_mono = $destChannel['opusEncode']->resample($pcmCache, $sourceFreq, 48000);
                    // Encode com frequência explícita (48000Hz)
                    return $destChannel['opusEncode']->encode($pcm48_mono, 48000);
                },
                'L16' => function() use ($pcmCache, $sourceFreq, $targetFreq) {
                    return resampler($pcmCache, $sourceFreq, $targetFreq, true);
                },
                default => fn() => $packet->getPayload(),
            };

            // Executar encoding
            $encodedData = $encodedData();

            // Incrementar timestamp baseado na frequência
            $timestampIncrement = (int)($targetFreq * 0.02); // 20ms
            $destChannel['timestamp'] = ($destChannel['timestamp'] + $timestampIncrement) & 0xFFFFFFFF;

            // Construir pacote RTP com canal dedicado
            $destChannel['rtpChannel']->setPayloadType($target->getPayloadType());
            $destChannel['rtpChannel']->setFrequency($targetFreq);
            $destChannel['rtpChannel']->setSsrc($destChannel['ssrc']);

            $outputPacket = $destChannel['rtpChannel']->buildAudioPacket($encodedData);

            // Envia o pacote
            $this->socket->sendto(
                $target->getAddress(),
                $target->getPort(),
                $outputPacket
            );

            $target->incrementPacketsSent();
        }
    }

    /**
     * Transcodifica pacote se os codecs forem diferentes
     * RFC 3550 Section 7.1: General Description of Translators
     *
     * @param RtpPacket $packet Pacote original
     * @param RtpEndpoint $source Endpoint de origem
     * @param RtpEndpoint $target Endpoint de destino
     * @return string Pacote RTP transcodificado serializado
     */
    private function transcodeIfNeeded(RtpPacket $packet, RtpEndpoint $source, RtpEndpoint $target): string
    {
        $sourceCodec = strtoupper($source->getCodec());
        $targetCodec = strtoupper($target->getCodec());
        $sourceFreq = $source->getFrequency();
        $targetFreq = $target->getFrequency();

        // Passo 1: Decodificar para PCM (usando canal do source)
        $pcmData = match ($sourceCodec) {
            'PCMU' => decodePcmuToPcm($packet->getPayload()),
            'PCMA' => decodePcmaToPcm($packet->getPayload()),
            'G729' => $source->getBcg729Channel()->decode($packet->getPayload()),
            'OPUS' => $source->getOpusChannel()->decode($packet->getPayload()),
            'L16' => pcmLeToBe($packet->getPayload()),
            default => $packet->getPayload(),
        };

        // Passo 2: Resampling baseado no codec de destino
        $encodedData = match ($targetCodec) {
            'PCMU' => function() use ($pcmData, $sourceFreq) {
                // PCMU precisa de 8000Hz
                if ($sourceFreq !== 8000) {
                    $pcmData = resampler($pcmData, $sourceFreq, 8000);
                }
                return encodePcmToPcmu($pcmData);
            },
            'PCMA' => function() use ($pcmData, $sourceFreq) {
                // PCMA precisa de 8000Hz
                if ($sourceFreq !== 8000) {
                    $pcmData = resampler($pcmData, $sourceFreq, 8000);
                }
                return encodePcmToPcma($pcmData);
            },
            'G729' => function() use ($pcmData, $sourceFreq, $target) {
                // G729 precisa de 8000Hz
                if ($sourceFreq !== 8000) {
                    $pcmData = resampler($pcmData, $sourceFreq, 8000);
                }
                return $target->getBcg729Channel()->encode($pcmData);
            },
            'OPUS' => function() use ($pcmData, $sourceFreq, $target) {
                // OPUS precisa de 48000Hz
                $pcm48k = resampler($pcmData, $sourceFreq, 48000);
                return $target->getOpusChannel()->encode($pcm48k);
            },
            'L16' => function() use ($pcmData, $sourceFreq, $targetFreq) {
                // L16 usa a frequência do target
                return resampler($pcmData, $sourceFreq, $targetFreq, true);
            },
            default => fn() => $packet->getPayload(),
        };

        // Executar a função de encoding
        $encodedData = $encodedData();

        // Passo 3: Construir novo pacote RTP com rtpChannel do target
        $rtpChannel = $target->getRtpChannel();
        $rtpChannel->setPayloadType($target->getPayloadType());
        $rtpChannel->setFrequency($target->getFrequency());
        $rtpChannel->setSsrc($target->getSsrc());

        return $rtpChannel->buildAudioPacket($encodedData);
    }

    /**
     * Processa pacote DTMF
     * RFC 4733: RTP Payload for DTMF Digits
     *
     * @param RtpPacket $packet Pacote contendo evento DTMF
     * @param array $peer Informações do peer de origem
     */
    private function processDtmfPacket(RtpPacket $packet, array $peer): void
    {
        $dtmfEvent = $this->dtmfProcessor->process($packet);

        if ($dtmfEvent !== null && is_callable($this->callbacks['onDtmf'])) {
            Coroutine::create($this->callbacks['onDtmf'], $dtmfEvent, $peer, $this);
        }

        // Faz forward do DTMF para outros endpoints
        $sourceId = "{$peer['address']}:{$peer['port']}";
        foreach ($this->endpoints as $endpointId => $target) {
            if ($endpointId === $sourceId) {
                continue;
            }

            $this->socket->sendto(
                $target->getAddress(),
                $target->getPort(),
                $packet->serialize()
            );
        }
    }

    /**
     * Processa VAD (Voice Activity Detection)
     * ITU-T G.729 Annex B: VAD Algorithm
     *
     * @param RtpPacket $packet Pacote de áudio
     * @param string $endpointId ID do endpoint
     */
    private function processVad(RtpPacket $packet, string $endpointId): void
    {
        if ($this->vadProcessor === null) {
            return;
        }

        $pcmData = $this->decodeAudio(
            $packet->getPayload(),
            $this->endpoints[$endpointId]->getCodec()
        );

        $vadResult = $this->vadProcessor->process($pcmData, $endpointId);

        if ($vadResult->hasStateChanged() && is_callable($this->callbacks['onVadChange'])) {
            Coroutine::create(
                $this->callbacks['onVadChange'],
                $vadResult->isActive(),
                $vadResult->getEnergy(),
                $endpointId
            );
        }
    }

    /**
     * Habilita VAD (Voice Activity Detection)
     *
     * @param float $threshold Limiar de energia para detecção
     * @param int $hangoverFrames Frames de sustentação após silêncio
     */
    public function enableVad(float $threshold = 2.0, int $hangoverFrames = 20): void
    {
        $this->vadProcessor = new VadProcessor($threshold, $hangoverFrames);
        cli::pcl("[RTP] VAD habilitado - Threshold: {$threshold}, Hangover: {$hangoverFrames}", 'green');
    }

    /**
     * Desabilita VAD
     */
    public function disableVad(): void
    {
        $this->vadProcessor = null;
        cli::pcl("[RTP] VAD desabilitado", 'yellow');
    }

    /**
     * Registra callback para evento
     *
     * @param string $event Nome do evento
     * @param callable $callback Função callback
     */
    public function on(string $event, callable $callback): void
    {
        if (array_key_exists($event, $this->callbacks)) {
            $this->callbacks[$event] = $callback;
        }
    }

    /**
     * Bloqueia execução até o proxy finalizar
     *
     * @param callable|null $callback Callback a executar antes de bloquear
     */
    public function block(?callable $callback = null): void
    {
        if ($callback !== null) {
            $callback($this);
        }
        $this->blockChannel->pop();
    }

    /**
     * Desbloqueia o proxy
     */
    public function unblock(): void
    {
        if ($this->blockChannel->length() === 0) {
            $this->blockChannel->push(true);
        }
    }

    /**
     * Para o proxy
     */
    public function stop(): void
    {
        $this->isActive = false;
        $this->unblock();
        cli::pcl("[RTP] Parando proxy para sessão {$this->sessionId}", 'yellow');
    }

    /**
     * Fecha o proxy e libera recursos
     */
    public function close(): void
    {
        $this->stop();

        // Limpar todos os canais de destino (libera memória dos codecs)
        foreach ($this->destinationChannels as $key => &$channel) {
            if (isset($channel['bcg729Decode'])) {
                $channel['bcg729Decode'] = null;
            }
            if (isset($channel['bcg729Encode'])) {
                $channel['bcg729Encode'] = null;
            }
            if (isset($channel['opusDecode'])) {
                $channel['opusDecode'] = null;
            }
            if (isset($channel['opusEncode'])) {
                $channel['opusEncode'] = null;
            }
            if (isset($channel['rtpChannel'])) {
                $channel['rtpChannel'] = null;
            }
        }
        $this->destinationChannels = [];

        // Fecha endpoints
        foreach ($this->endpoints as $endpoint) {
            $endpoint->close();
        }

        $this->socket->close();
        cli::pcl("[RTP] Proxy fechado para sessão {$this->sessionId}", 'red');
    }

    /**
     * Verifica timeout de inatividade
     *
     * @return bool True se timeout excedido
     */
    private function checkInactivityTimeout(): bool
    {
        return (microtime(true) - $this->lastPacketTime) > $this->inactivityTimeout;
    }

    /**
     * Trata timeout de inatividade
     */
    private function handleTimeout(): void
    {
        cli::pcl("[RTP] Timeout de inatividade para sessão {$this->sessionId}", 'red');

        if (is_callable($this->callbacks['onTimeout'])) {
            Coroutine::create($this->callbacks['onTimeout'], $this);
        }

        $this->stop();
    }

    /**
     * Define timeout de inatividade
     *
     * @param int $seconds Segundos de timeout
     */
    public function setInactivityTimeout(int $seconds): void
    {
        $this->inactivityTimeout = $seconds;
    }

    /**
     * Obtém estatísticas da sessão
     *
     * @return array Array com estatísticas
     */
    public function getStatistics(): array
    {
        return $this->statistics->toArray();
    }

    /**
     * Gera SSRC deterministicamente baseado no endpoint ID
     * RFC 3550 Section 5.1: RTP Fixed Header Fields
     *
     * @param string $endpointId ID do endpoint
     * @return int SSRC de 32 bits
     */
    private function generateSsrc(string $endpointId): int
    {
        return crc32($endpointId) & 0xFFFFFFFF;
    }

    /**
     * Verifica se payload type é DTMF
     * RFC 4733 Section 3: Payload Format
     *
     * @param int $payloadType Tipo do payload
     * @return bool True se for DTMF
     */
    private function isDtmfPayload(int $payloadType): bool
    {
        // Payload types dinâmicos para telephone-event (96-127)
        // 101 é o valor padrão mais comum
        return in_array($payloadType, [101, 96, 97, 98, 99, 100]);
    }
}

/**
 * Classe representando um endpoint RTP
 * RFC 3550 Section 3: Definitions
 */
class RtpEndpoint
{
    private string $address;
    private int $port;
    private string $codec;
    private int $payloadType;
    private int $frequency;
    private int $ssrc;
    private int $sequenceNumber = 0;
    private int $timestamp = 0;
    private float $lastActivity;
    private int $packetsSent = 0;
    private int $packetsReceived = 0;

    public function __construct(
        string $address,
        int    $port,
        string $codec,
        int    $payloadType,
        int    $frequency,
        int    $ssrc
    )
    {
        $this->address = $address;
        $this->port = $port;
        $this->codec = $codec;
        $this->payloadType = $payloadType;
        $this->frequency = $frequency;
        $this->ssrc = $ssrc;
        $this->lastActivity = microtime(true);
        $this->timestamp = rand(0, 0xFFFFFFFF);
    }

    public function getAddress(): string
    {
        return $this->address;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getCodec(): string
    {
        return $this->codec;
    }

    public function getPayloadType(): int
    {
        return $this->payloadType;
    }

    public function getFrequency(): int
    {
        return $this->frequency;
    }

    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    public function getNextSequence(): int
    {
        return ++$this->sequenceNumber & 0xFFFF;
    }

    public function getNextTimestamp(): int
    {
        // RFC 3550: timestamp increment = samples per packet
        // Para 20ms @ 8000Hz = 160 samples
        $increment = (int)($this->frequency * 0.02);
        $this->timestamp += $increment;
        return $this->timestamp & 0xFFFFFFFF;
    }

    public function updateActivity(): void
    {
        $this->lastActivity = microtime(true);
        $this->packetsReceived++;
    }

    public function incrementPacketsSent(): void
    {
        $this->packetsSent++;
    }

    public function close(): void
    {
        // Cleanup de recursos se necessário
    }
}

/**
 * Classe para parsing e construção de pacotes RTP
 * RFC 3550 Section 5.1: RTP Fixed Header Fields
 */
class RtpPacket
{
    private int $version;
    private int $padding;
    private int $extension;
    private int $csrcCount;
    private int $marker;
    private int $payloadType;
    private int $sequenceNumber;
    private int $timestamp;
    private int $ssrc;
    private string $payload;

    public function __construct(string $packet = '')
    {
        if (!empty($packet)) {
            $this->parse($packet);
        }
    }

    /**
     * Parse de pacote RTP binário
     * RFC 3550 Section 5.1
     */
    private function parse(string $packet): void
    {
        if (strlen($packet) < 12) {
            var_dump($packet);
            $this->version = $this->padding = $this->extension = $this->csrcCount = 0;
        }

        $header = unpack('Cbyte0/Cbyte1/nseq/Nts/Nssrc', $packet);

        $this->version = ($header['byte0'] >> 6) & 0x03;
        $this->padding = ($header['byte0'] >> 5) & 0x01;
        $this->extension = ($header['byte0'] >> 4) & 0x01;
        $this->csrcCount = $header['byte0'] & 0x0F;

        $this->marker = ($header['byte1'] >> 7) & 0x01;
        $this->payloadType = $header['byte1'] & 0x7F;

        $this->sequenceNumber = $header['seq'];
        $this->timestamp = $header['ts'];
        $this->ssrc = $header['ssrc'];

        // Pula CSRC se houver
        $headerLength = 12 + ($this->csrcCount * 4);

        // Pula extensão se houver
        if ($this->extension) {
            $extHeader = unpack('nprofile/nlength', substr($packet, $headerLength, 4));
            $headerLength += 4 + ($extHeader['length'] * 4);
        }

        $this->payload = substr($packet, $headerLength);
    }

    /**
     * Serializa pacote RTP para formato binário
     * RFC 3550 Section 5.1
     */
    public function serialize(): string
    {
        $byte0 = ($this->version << 6) |
            ($this->padding << 5) |
            ($this->extension << 4) |
            $this->csrcCount;

        $byte1 = ($this->marker << 7) | $this->payloadType;

        return pack(
                'CCnNN',
                $byte0,
                $byte1,
                $this->sequenceNumber,
                $this->timestamp,
                $this->ssrc
            ) . $this->payload;
    }

    // Getters
    public function getVersion(): int
    {
        return $this->version;
    }

    public function getPadding(): int
    {
        return $this->padding;
    }

    public function getExtension(): int
    {
        return $this->extension;
    }

    public function getCsrcCount(): int
    {
        return $this->csrcCount;
    }

    public function getMarker(): int
    {
        return $this->marker;
    }

    public function getPayloadType(): int
    {
        return $this->payloadType;
    }

    public function getSequenceNumber(): int
    {
        return $this->sequenceNumber;
    }

    public function getTimestamp(): int
    {
        return $this->timestamp;
    }

    public function getSsrc(): int
    {
        return $this->ssrc;
    }

    public function getPayload(): string
    {
        return $this->payload;
    }

    // Métodos fluentes para modificação
    public function withSsrc(int $ssrc): self
    {
        $clone = clone $this;
        $clone->ssrc = $ssrc;
        return $clone;
    }

    public function withTimestamp(int $timestamp): self
    {
        $clone = clone $this;
        $clone->timestamp = $timestamp;
        return $clone;
    }
}

/**
 * Processador de eventos DTMF
 * RFC 4733: RTP Payload for DTMF Digits
 */
class DtmfProcessor
{
    private array $eventCache = [];

    /**
     * Processa pacote DTMF
     * RFC 4733 Section 2.3: Event Payload Format
     */
    public function process(RtpPacket $packet): ?array
    {
        $payload = $packet->getPayload();

        if (strlen($payload) < 4) {
            return null;
        }

        // Parse do evento DTMF
        $data = unpack('Cevent/Cflags/nduration', $payload);

        $event = $data['event'];
        $isEnd = ($data['flags'] & 0x80) !== 0;
        $volume = $data['flags'] & 0x3F;
        $duration = $data['duration'];

        $cacheKey = "{$packet->getSsrc()}:{$packet->getTimestamp()}:{$event}";

        // Verifica se é evento duplicado
        if (isset($this->eventCache[$cacheKey])) {
            if ($this->eventCache[$cacheKey]['processed'] && $isEnd) {
                return null; // Retransmissão
            }
            $this->eventCache[$cacheKey]['duration'] = $duration;
        } else {
            $this->eventCache[$cacheKey] = [
                'event' => $event,
                'timestamp' => $packet->getTimestamp(),
                'duration' => $duration,
                'volume' => $volume,
                'processed' => false,
            ];
        }

        // Retorna apenas quando o evento termina
        if ($isEnd && !$this->eventCache[$cacheKey]['processed']) {
            $this->eventCache[$cacheKey]['processed'] = true;

            // Limpa cache antigo
            $this->cleanCache();

            return [
                'digit' => $this->eventToDigit($event),
                'event' => $event,
                'duration' => $duration,
                'volume' => $volume,
            ];
        }

        return null;
    }

    /**
     * Converte código de evento para dígito
     * RFC 4733 Table 3: DTMF Named Events
     */
    private function eventToDigit(int $event): string
    {
        return match ($event) {
            0, 1, 2, 3, 4, 5, 6, 7, 8, 9 => (string)$event,
            10 => '*',
            11 => '#',
            12 => 'A',
            13 => 'B',
            14 => 'C',
            15 => 'D',
            16 => 'flash',
            default => '?',
        };
    }

    /**
     * Limpa cache antigo (> 5 segundos)
     */
    private function cleanCache(): void
    {
        $currentTime = time();
        $this->eventCache = array_filter(
            $this->eventCache,
            fn($item) => ($currentTime - $item['timestamp']) < 5
        );
    }
}

/**
 * Processador de VAD (Voice Activity Detection)
 * ITU-T G.729 Annex B
 */
class VadProcessor
{
    private float $threshold;
    private int $hangoverFrames;
    private array $states = [];

    public function __construct(float $threshold = 2.0, int $hangoverFrames = 20)
    {
        $this->threshold = $threshold;
        $this->hangoverFrames = $hangoverFrames;
    }

    /**
     * Processa frame de áudio para VAD
     */
    public function process(string $pcmData, string $endpointId): VadResult
    {
        if (!isset($this->states[$endpointId])) {
            $this->states[$endpointId] = [
                'isActive' => false,
                'hangover' => 0,
                'avgEnergy' => 0.0,
            ];
        }

        $state = &$this->states[$endpointId];
        $wasActive = $state['isActive'];

        // Calcula energia do sinal
        $energy = $this->calculateEnergy($pcmData);

        // Atualiza média móvel
        $state['avgEnergy'] = $state['avgEnergy'] * 0.9 + $energy * 0.1;

        // Detecta atividade
        if ($energy > $this->threshold) {
            $state['isActive'] = true;
            $state['hangover'] = $this->hangoverFrames;
        } elseif ($state['hangover'] > 0) {
            $state['hangover']--;
            $state['isActive'] = true;
        } else {
            $state['isActive'] = false;
        }

        return new VadResult(
            $state['isActive'],
            $energy,
            $wasActive !== $state['isActive']
        );
    }

    /**
     * Calcula energia RMS do sinal PCM
     */
    private function calculateEnergy(string $pcmData): float
    {
        $samples = unpack('s*', $pcmData);
        $sum = 0;

        foreach ($samples as $sample) {
            $sum += $sample * $sample;
        }

        return sqrt($sum / count($samples)) / 32768.0 * 100.0;
    }
}

/**
 * Resultado do processamento VAD
 */
class VadResult
{
    private bool $isActive;
    private float $energy;
    private bool $stateChanged;

    public function __construct(bool $isActive, float $energy, bool $stateChanged)
    {
        $this->isActive = $isActive;
        $this->energy = $energy;
        $this->stateChanged = $stateChanged;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getEnergy(): float
    {
        return $this->energy;
    }

    public function hasStateChanged(): bool
    {
        return $this->stateChanged;
    }
}

/**
 * Classe para estatísticas RTP
 * RFC 3550 Section 6.4: Sender and Receiver Reports
 */
class RtpStatistics
{
    private int $totalPackets = 0;
    private int $lostPackets = 0;
    private float $avgJitter = 0.0;
    private float $startTime;
    private float $voiceTime = 0.0;
    private float $silenceTime = 0.0;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    public function incrementPackets(): void
    {
        $this->totalPackets++;
    }

    public function incrementLostPackets(): void
    {
        $this->lostPackets++;
    }

    public function addVoiceTime(float $duration): void
    {
        $this->voiceTime += $duration;
    }

    public function addSilenceTime(float $duration): void
    {
        $this->silenceTime += $duration;
    }

    public function toArray(): array
    {
        $duration = microtime(true) - $this->startTime;

        return [
            'total_packets' => $this->totalPackets,
            'lost_packets' => $this->lostPackets,
            'loss_rate' => $this->totalPackets > 0 ?
                ($this->lostPackets / $this->totalPackets) * 100 : 0,
            'avg_jitter' => $this->avgJitter,
            'duration' => round($duration, 2),
            'voice_time' => round($this->voiceTime, 2),
            'silence_time' => round($this->silenceTime, 2),
            'packets_per_second' => $duration > 0 ?
                round($this->totalPackets / $duration, 2) : 0,
        ];
    }
}