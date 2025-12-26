<?php

namespace libspech\Rtp;

use bcg729Channel;
use Closure;
use InvalidArgumentException;
use opusChannel;
use RuntimeException;

class rtpChannel
{
    public const RTP_VERSION = 2;
    public const RTP_HEADER_FORMAT = 'CCnNN';
    public const RTP_HEADER_SIZE = 12;
    public const FINAL_PACKET_COUNT = 3;

    // Tipos de payload de áudio
    public const PAYLOAD_PCMU = 0;   // G.711 µ-law
    public const PAYLOAD_PCMA = 8;   // G.711 A-law
    public const PAYLOAD_G729 = 18;  // G.729
    public const PAYLOAD_DTMF = 101; // RFC 2833 DTMF

    public int $payloadType;
    public int $sampleRate;
    public int $packetTimeMs;
    public int $samplesPerPacket;
    public int $sequenceNumber;
    public int $timestamp;
    public int $ssrc;
    public bool $markerBit;
    public ?int $dtmfStartTimestamp = null;
    public ?DtmfEvent $currentDtmfEvent = null;
    private int $payloadDTMF = 101;
    private bool $dtmfEventActive = false;
    private int $lastDtmfTimestamp = 0;

    public bcg729Channel $bcg729Channel {
        get {
            return $this->bcg729Channel;
        }
        set {
            $this->bcg729Channel = $value;
        }

    }
    public opusChannel $opusChannel;

    public function __construct(int $payloadType = self::PAYLOAD_PCMU, int $sampleRate = 8000, int $packetTimeMs = 20, ?int $ssrc = null)
    {
        $this->validatePayloadType($payloadType);
        $this->validateSampleRate($sampleRate);
        $this->validatePacketTime($packetTimeMs);
        $this->bcg729Channel = new bcg729Channel;
        $opus = new opusChannel(48000, 1);
        $opus->setBitrate(24000);
        $opus->setSignalVoice(true);
        $opus->setDTX(true);
        $this->opusChannel = $opus;


        $this->payloadType = $payloadType;
        $this->sampleRate = $sampleRate;
        $this->packetTimeMs = $packetTimeMs;
        $this->samplesPerPacket = $sampleRate * $packetTimeMs / 1000;
        $this->sequenceNumber = random_int(0, 0xffff);
        $this->timestamp = random_int(0, 0xffffffff);
        $this->ssrc = $ssrc ?? random_int(0, 0xffffffff);
        $this->markerBit = false;
        $this->payloadDTMF = 101;
        $this->dtmfEventActive = false;
        $this->lastDtmfTimestamp = 0;


    }

    public function setNewPtDTMF(int $v): void
    {
        $this->payloadDTMF = $v;
    }

    public function validatePayloadType(int $payloadType): void
    {
        if ($payloadType < 0 || $payloadType > 127) {
            throw new InvalidArgumentException("Payload type deve estar entre 0 e 127");
        }
    }

    public function validateSampleRate(int $sampleRate): void
    {
        if ($sampleRate <= 0) {
            throw new InvalidArgumentException("Sample rate deve ser maior que 0");
        }
    }

    public function validatePacketTime(int $packetTimeMs): void
    {
        if ($packetTimeMs <= 0) {
            throw new InvalidArgumentException("Packet time deve ser maior que 0");
        }
    }

    public function setSsrc(int $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    public function setPayloadType(int $payloadType): void
    {
        $this->validatePayloadType($payloadType);
        $this->payloadType = $payloadType;
    }

    public function setSampleRate(int $sampleRate): void
    {
        $this->validateSampleRate($sampleRate);
        $this->sampleRate = $sampleRate;
        $this->samplesPerPacket = $sampleRate * $this->packetTimeMs / 1000;
    }

    public function setFrequency(int $frequency): void
    {
        if ($frequency <= 7999) return;
        $this->setSampleRate($frequency);
    }

    public function setMarkerBit(bool $marker = true): void
    {
        $this->markerBit = $marker;
    }

    public function buildAudioPacket(string $audioPayload, bool $incrementTimestamp = true): string
    {
        $packet = $this->buildRtpHeader($this->payloadType, $this->timestamp) . $audioPayload;
        $this->sequenceNumber++;
        if ($incrementTimestamp) {
            $this->timestamp += $this->samplesPerPacket;
        }
        return $packet;
    }

    /**
     * Constrói um pacote RTP para forward de DTMF (telephone-event)
     * Mantém o timestamp original do evento DTMF e usa o PT de telephone-event correto
     *
     * @param string $dtmfPayload Payload raw do pacote DTMF (4 bytes: event, flags, duration)
     * @param int $dtmfTimestamp Timestamp original do evento DTMF (deve ser constante durante o evento)
     * @param bool $markerBit Se true, define o marker bit (primeiro pacote do evento)
     * @return string Pacote RTP completo para envio
     */
    public function buildDtmfForwardPacket(string $dtmfPayload, int $dtmfTimestamp, bool $markerBit = false): string
    {
        // Salvar estado atual
        $originalMarker = $this->markerBit;
        $originalPayloadType = $this->payloadType;

        // Configurar para DTMF
        $this->markerBit = $markerBit;

        // Construir header com PT de telephone-event e timestamp fixo do DTMF
        $packet = $this->buildRtpHeader($this->payloadDTMF, $dtmfTimestamp) . $dtmfPayload;

        // Incrementar apenas o sequence number (timestamp NÃO muda durante evento DTMF)
        $this->sequenceNumber++;

        // Restaurar estado
        $this->markerBit = $originalMarker;
        $this->payloadType = $originalPayloadType;

        return $packet;
    }

    public function buildRtpHeader(int $payloadType, int $timestamp): string
    {
        if ($payloadType === self::PAYLOAD_DTMF) $payloadType = $this->payloadDTMF;
        $version = self::RTP_VERSION << 6;
        $firstByte = $version;
        $marker = $this->markerBit ? 0x80 : 0x0;
        $secondByte = $marker | $payloadType & 0x7f;
        $this->markerBit = false;
        return pack(self::RTP_HEADER_FORMAT, $firstByte, $secondByte, $this->sequenceNumber & 0xffff, $timestamp, $this->ssrc);
    }

    private function buildDtmfPacket(DtmfEvent $event, bool $isFirstPacket = false): string
    {
        if ($isFirstPacket || $this->dtmfStartTimestamp === null) {
            $this->dtmfStartTimestamp = $this->timestamp;
            $this->currentDtmfEvent = $event;
            $this->setMarkerBit(true);
            $this->dtmfEventActive = true;
        }

        $duration = $this->calculateDtmfDuration();
        $event->setDuration($duration);
        $payload = $event->generatePayload();

        $packet = $this->buildRtpHeader($this->payloadDTMF, $this->dtmfStartTimestamp) . $payload;
        $this->sequenceNumber++;
        return $packet;
    }

    public function finalizeDtmfSequence(): array
    {
        if ($this->currentDtmfEvent === null) return [];

        $this->currentDtmfEvent->markEnd();
        $packets = [];
        $finalDuration = $this->calculateDtmfDuration();

        for ($i = 0; $i < self::FINAL_PACKET_COUNT; $i++) {
            $this->currentDtmfEvent->setDuration($finalDuration);
            $payload = $this->currentDtmfEvent->generatePayload();
            $packet = $this->buildRtpHeader(self::PAYLOAD_DTMF, $this->dtmfStartTimestamp) . $payload;
            $packets[] = $packet;
            $this->sequenceNumber++;
        }

        $this->resetDtmfState();
        return $packets;
    }

    public function sendFinalDtmfPackets(Closure $packetSender, int $packetIntervalMs = 0, $extra = false): void
    {
        if ($this->currentDtmfEvent === null) return;

        $this->currentDtmfEvent->markEnd();
        $finalDuration = $this->calculateDtmfDuration();

        for ($i = 0; $i < self::FINAL_PACKET_COUNT; $i++) {
            $this->currentDtmfEvent->setDuration($finalDuration);
            $payload = $this->currentDtmfEvent->generatePayload();
            $finalPacket = $this->buildRtpHeader(self::PAYLOAD_DTMF, $this->dtmfStartTimestamp) . $payload;
            $this->sequenceNumber++;
            $packetSender($finalPacket, $extra);

            if ($packetIntervalMs > 0 && $i < self::FINAL_PACKET_COUNT - 1) {
                //usleep($packetIntervalMs * 1000);
            }
        }

        $this->resetDtmfState();
    }

    public function resetDtmfState(): void
    {
        $this->dtmfStartTimestamp = null;
        $this->currentDtmfEvent = null;
        $this->dtmfEventActive = false;
    }

    public function generateDtmfSequence(string $dtmfString, int $eventDurationMs = 100): array
    {
        $packets = [];
        $currentTimestamp = $this->timestamp;

        foreach (str_split($dtmfString) as $char) {
            $this->timestamp = $currentTimestamp;
            $event = new DtmfEvent(DtmfEvent::charToEvent($char));

            $packets[] = $this->buildDtmfPacket($event, true);
            $packetsForEvent = max(1, intval($eventDurationMs / $this->packetTimeMs));

            for ($i = 1; $i < $packetsForEvent; $i++) {
                $currentTimestamp += $this->samplesPerPacket;
                $this->timestamp = $currentTimestamp;
                $packets[] = $this->buildDtmfPacket($event);
            }

            $currentTimestamp += $this->samplesPerPacket;
            $this->timestamp = $currentTimestamp;
            $finalPackets = $this->finalizeDtmfSequence();
            $packets = array_merge($packets, $finalPackets);

            $currentTimestamp += $this->samplesPerPacket * 2;
        }

        $this->timestamp = $currentTimestamp;
        return $packets;
    }

    public function sendDtmfSequence(string $dtmfString, callable $packetSender, callable $finalPacketSender, int $eventDurationMs = 100, int $pauseBetweenDigitsMs = 50, int $packetIntervalMs = 10): void
    {
        if (empty($dtmfString)) {
            throw new InvalidArgumentException("String DTMF não pode estar vazia");
        }

        $packetIntervalMs = $packetIntervalMs ?? $this->packetTimeMs;
        $packetsPerEvent = max(1, intval($eventDurationMs / $packetIntervalMs));
        $baseTimestamp = $this->timestamp;

        foreach (str_split($dtmfString) as $digitIndex => $char) {
            $event = new DtmfEvent(DtmfEvent::charToEvent($char));
            $this->initializeDtmfEvent($event);

            $firstPacket = $this->buildDtmfPacket($event, true);
            if (!$packetSender($firstPacket)) {
                throw new RuntimeException("Falha ao enviar primeiro pacote DTMF para dígito: {$char}");
            }

            for ($i = 1; $i < $packetsPerEvent; $i++) {
                $baseTimestamp += $this->samplesPerPacket;
                $this->timestamp = $baseTimestamp;
                $packet = $this->buildDtmfPacket($event);

                if (!$packetSender($packet)) {
                    throw new RuntimeException("Falha ao enviar pacote DTMF contínuo para dígito: {$char}");
                }
            }

            $baseTimestamp += $this->samplesPerPacket;
            $this->timestamp = $baseTimestamp;
            $this->sendFinalDtmfPackets($finalPacketSender, $packetIntervalMs);

            if ($digitIndex < strlen($dtmfString) - 1 && $pauseBetweenDigitsMs > 0) {
                $pausePackets = max(1, intval($pauseBetweenDigitsMs / $packetIntervalMs));
                $baseTimestamp += $this->samplesPerPacket * $pausePackets;
            }
        }

        $this->timestamp = $baseTimestamp;
    }

    public function initializeDtmfEvent(DtmfEvent $event): void
    {
        $this->dtmfStartTimestamp = $this->timestamp;
        $this->currentDtmfEvent = $event;
        $this->setMarkerBit(true);
        $this->dtmfEventActive = true;
    }

    private function calculateDtmfDuration(): int
    {
        if ($this->dtmfStartTimestamp === null) {
            return 0;
        }

        $delta = $this->timestamp - $this->dtmfStartTimestamp;
        if ($delta <= 0) return 160; // Mínimo de 20ms para 8kHz

        // Conversão correta: mantém a proporção do sample rate
        $durationIn8kHz = intval($delta * $this->sampleRate);
        // return max(160, $durationIn8kHz); // Mínimo de 160 amostras (20ms em 8kHz)
        return 320;
    }

    public function sendSingleDtmf(string $digit, callable $packetSender, int $eventDurationMs = 80, int $volume = 10): void
    {
        if (strlen($digit) !== 1) {
            throw new InvalidArgumentException("Deve ser fornecido apenas um dígito");
        }

        $event = new DtmfEvent(DtmfEvent::charToEvent($digit), $volume);
        $originalTimestamp = $this->timestamp;

        // 1) Pacote inicial com marker bit
        $this->initializeDtmfEvent($event);
        $startPacket = $this->buildDtmfPacket($event, true);
        if (!$packetSender($startPacket)) {
            throw new RuntimeException("Falha ao enviar pacote inicial DTMF");
        }

        // 2) Pacotes de continuação
        $packetsToSend = max(2, intval($eventDurationMs / $this->packetTimeMs));
        for ($i = 1; $i < $packetsToSend; $i++) {
            $this->timestamp += $this->samplesPerPacket;
            $packet = $this->buildDtmfPacket($event, false);
            if (!$packetSender($packet)) {
                throw new RuntimeException("Falha ao enviar pacote de continuação DTMF");
            }
        }

        // 3) Finalização
        $this->timestamp += $this->samplesPerPacket;
        $this->sendFinalDtmfPackets($packetSender, 0);

        // Avança o timestamp para próximos eventos
        $this->timestamp = $originalTimestamp + ($this->samplesPerPacket * ($packetsToSend + 4));
    }

    public function rfc2833(string $digit, callable $packetHandler, callable $finalPacketHandler, $extra = null): void
    {
        if (strlen($digit) !== 1) {
            throw new InvalidArgumentException("Deve ser fornecido apenas um dígito");
        }

        $event = new DtmfEvent(DtmfEvent::charToEvent($digit));
        $eventDurationMs = $this->packetTimeMs * 10;
        $packetsToSend = max(1, intval($eventDurationMs / $this->packetTimeMs));
        $initialTimestamp = $this->timestamp;

        $this->initializeDtmfEvent($event);

        for ($i = 0; $i < $packetsToSend; $i++) {
            if ($i > 0) {
                $this->timestamp += $this->samplesPerPacket;
            }
            $packet = $this->buildDtmfPacket($event, $i === 0);
            $packetHandler($packet, $extra);
        }

        $this->timestamp += $this->samplesPerPacket;
        $this->sendFinalDtmfPackets($finalPacketHandler, 0, $extra);

        // Garante espaçamento adequado entre eventos
        $this->timestamp = $initialTimestamp + ($this->samplesPerPacket * ($packetsToSend + 6));
    }

    public function sendMultipleDtmf(array $digits, callable $packetSender, int $intervalMs = 100): void
    {
        foreach ($digits as $index => $digit) {
            $this->sendSingleDtmf($digit, $packetSender);

            // Pausa entre dígitos (exceto no último)
            if ($index < count($digits) - 1) {
                $pausePackets = max(1, intval($intervalMs / $this->packetTimeMs));
                $this->timestamp += $this->samplesPerPacket * $pausePackets;
            }
        }
    }

    public function getChannelInfo(): array
    {
        return [
            'payloadType' => $this->payloadType,
            'sampleRate' => $this->sampleRate,
            'packetTimeMs' => $this->packetTimeMs,
            'samplesPerPacket' => $this->samplesPerPacket,
            'sequenceNumber' => $this->sequenceNumber,
            'timestamp' => $this->timestamp,
            'ssrc' => $this->ssrc,
            'dtmfEventActive' => $this->dtmfEventActive,
            'dtmfStartTimestamp' => $this->dtmfStartTimestamp,
        ];
    }
}
