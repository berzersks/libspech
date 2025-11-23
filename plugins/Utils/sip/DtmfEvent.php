<?php

class DtmfEvent
{
    // Dígitos 0-9
    public const int DTMF_0 = 0;
    public const int DTMF_1 = 1;
    public const int DTMF_2 = 2;
    public const int DTMF_3 = 3;
    public const int DTMF_4 = 4;
    public const int DTMF_5 = 5;
    public const int DTMF_6 = 6;
    public const int DTMF_7 = 7;
    public const int DTMF_8 = 8;
    public const int DTMF_9 = 9;

    // Símbolos especiais
    public const int DTMF_STAR = 10; // *
    public const int DTMF_HASH = 11; // #

    // Letras A- int
    public const int DTMF_A = 12;
    public const int DTMF_B = 13;
    public const int DTMF_C = 14;
    public const int DTMF_D = 15;

    private int $event;
    private bool $end = false;
    private int $volume = 10;
    private int $duration = 0;

    /**
     * @param int $event Código do evento DTMF (0-15)
     * @param int $volume Volume do evento (0-63, padrão 10)
     * @param int $duration Duração em amostras de timestamp
     * @throws InvalidArgumentException
     */
    public function __construct(int $event, int $volume = 10, int $duration = 0)
    {
        if ($event < 0 || $event > 15) {
            throw new InvalidArgumentException("Evento DTMF deve estar entre 0 e 15");
        }
        if ($volume < 0 || $volume > 63) {
            throw new InvalidArgumentException("Volume deve estar entre 0 e 63");
        }
        $this->event = $event;
        $this->volume = $volume;
        $this->duration = $duration;
    }

    /**
     * Converte caractere para código de evento DTMF
     */
    public static function charToEvent(string $char): int
    {
        return match (strtoupper($char)) {
            '0' => self::DTMF_0,
            '1' => self::DTMF_1,
            '2' => self::DTMF_2,
            '3' => self::DTMF_3,
            '4' => self::DTMF_4,
            '5' => self::DTMF_5,
            '6' => self::DTMF_6,
            '7' => self::DTMF_7,
            '8' => self::DTMF_8,
            '9' => self::DTMF_9,
            '*' => self::DTMF_STAR,
            '#' => self::DTMF_HASH,
            'A' => self::DTMF_A,
            'B' => self::DTMF_B,
            'C' => self::DTMF_C,
            'D' => self::DTMF_D,
            default => throw new InvalidArgumentException("Caractere DTMF inválido: {$char}"),
        };
    }

    public function markEnd(): void
    {
        $this->end = true;
    }

    public function setDuration(int $duration): void
    {
        $this->duration = $duration;
    }

    public function generatePayload(): string
    {
        $firstByte = $this->event & 0xff;
        $secondByte = ($this->end ? 0x80 : 0x0) | $this->volume & 0x3f;
        return pack('CCn', $firstByte, $secondByte, $this->duration);
    }

    public function setEnd(bool $param): void
    {
        $this->end = $param;
    }
}