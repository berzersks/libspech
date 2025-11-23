<?php

use Plugin\Utils\cli;

class rtpChannels
{
    private string $format = 'CCnNN';
    private int $version = 0x80;
    public int $codec = 0;
    public int $ptimeSamples = 160;
    public int $timestamp = 0;
    public int $seq = 0;
    public int $ssrc = 0;
    public bool $mark = false;
    private ?int $dtmfTimestamp = null;

    public function __construct(int $codec, int $ptimeSamples = 160, ?int $ssrc = null)
    {
        $this->codec = $codec;
        $this->ptimeSamples = $ptimeSamples;
        $this->ssrc = $ssrc ?? random_int(0, 0xFFFFFFFF);
        $this->format = 'CCnNN';
        $this->seq = 0;
        $this->timestamp = 0;
        $this->mark = false;
        $this->dtmfTimestamp = null;
    }

    public function ssrcDefine($ssrc): void
    {
        $this->ssrc = $ssrc;
    }

    public function setCodec(int $codec): void
    {
        $this->codec = $codec;
    }

    public function setPtimeSamples(int $ptimeSamples): void
    {
        $this->ptimeSamples = $ptimeSamples;
    }

    public function build(string $payload, bool $registerTime = true, bool $isDtmf = false): string
    {
        if ($isDtmf) {
            if ($this->dtmfTimestamp === null) {
                $this->dtmfTimestamp = $this->timestamp;
            }
            $timestamp = $this->dtmfTimestamp;
            $payloadType = 101;
        }

        else {
            if ($registerTime) {
                $this->timestamp += $this->ptimeSamples;
            }
            $timestamp = $this->timestamp;
            $payloadType = $this->codec;
        }
        $firstByte = $this->version;
        $secondByte = ($this->mark ? 0x80 : 0x00) | ($payloadType & 0x7F);
        $this->mark = false;
        $packet = pack($this->format, $firstByte, $secondByte, $this->seq, $timestamp, $this->ssrc) . $payload;
        $this->seq++;
        if (!$isDtmf) {
            $this->dtmfTimestamp = null;
        }

        return $packet;
    }
}
