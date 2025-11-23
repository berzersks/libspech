<?php


use Plugin\Utils\cli;

class rtpc
{
    private string $format = 'CCnNN';
    public string $rawPacket = '';

    public int $version = 2;
    public int $padding = 0;
    public int $extension = 0;
    public int $cc = 0;
    public int $marker = 0;
    public int $payloadType = 0;
    public int $sequence = 0;
    public int $timestamp = 0;
    public int $ssrc = 0;

    public string $payloadRaw = '';

    public function __construct(?string $packet)
    {
        if ($packet === null) return;
        if (strlen($packet) < 12) return;
        $this->rawPacket = $packet;
        $this->payloadRaw = substr($packet, 12);

        $this->version = ord($packet[0]) >> 6;
        $this->padding = (ord($packet[0]) >> 5) & 0x01;
        $this->extension = (ord($packet[0]) >> 4) & 0x01;
        $this->cc = ord($packet[0]) & 0x0F;
        $this->marker = (ord($packet[1]) >> 7) & 0x01;
        $this->payloadType = ord($packet[1]) & 0x7F;
        $this->sequence = unpack('n', substr($packet, 2, 2))[1];
        $this->timestamp = unpack('N', substr($packet, 4, 4))[1];
        $this->ssrc = unpack('N', substr($packet, 8, 4))[1];
    }

    public function getCodec(): int
    {
        $codec = $this->payloadType & 0x7F;
        return $codec;
    }

    public function __destruct()
    {
        $clean = 0;
        foreach ($this as $key => $value) {
            unset($this->$key);
            $clean++;
        }
        return $clean;
    }

    public function setPayloadType( $payloadType=0): void
    {
        if (!$payloadType) $payloadType = 0;
        $this->payloadType = $payloadType & 0x7F;
    }

    public function setSequence(int $sequence): void
    {
        $this->sequence = $sequence;
    }

    public function setTimestamp(int $timestamp): void
    {
        $this->timestamp = $timestamp;
    }

    public function setSsrc(int $ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    public function setMarker(int $marker): void
    {
        $this->marker = $marker & 0x01;
    }

    /**
     * Constrói o pacote RTP completo com o payload codificado
     * usando os valores atuais do cabeçalho
     *
     * @param false|string $encoded O payload codificado a ser adicionado ao cabeçalho RTP
     * @return false|string Pacote RTP completo ou false em caso de erro
     */
    public function build(false|string $encoded): string
    {
        if ($encoded === false) {
            return false;
        }
        if (strlen($encoded) === 0) {
            return false;
        }
        $this->payloadRaw = $encoded;
        $firstByte = (($this->version & 0x03) << 6) |
            (($this->padding & 0x01) << 5) |
            (($this->extension & 0x01) << 4) |
            ($this->cc & 0x0F);

        $secondByte = (($this->marker & 0x01) << 7) |
            ($this->payloadType & 0x7F);

        $packet = pack(
                $this->format,
                $firstByte,
                $secondByte,
                $this->sequence,
                $this->timestamp,
                $this->ssrc
            ) . $this->payloadRaw;

        $this->rawPacket = $packet;

        return $packet;
    }

    public function verbose(): void
    {
        $message = "$this->ssrc: seq:$this->sequence ts:$this->timestamp pt:$this->payloadType real ts:" . str_replace('.', '', (string)microtime(true));
        cli::pcl($message, 'green');
    }

    public function getSequence()
    {
        return $this->sequence;
    }

}