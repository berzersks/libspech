<?php

namespace Extension\plugins;


class utils
{
    public static function baseDir(): ?string
    {
        return explode('plugins', __DIR__)[0];
    }

    public static function bin2ascii(string $string): string
    {
        $ascii = '';
        for ($i = 0; $i < strlen($string); $i++) {
            $ascii .= ord($string[$i]) . ' ';
        }
        return trim($ascii);
    }
}

function pcmToUlaw(string $pcm): string {
    $ulaw = '';
    $len = strlen($pcm);

    for ($i = 0; $i < $len; $i += 2) {
        $sample = unpack('s', substr($pcm, $i, 2))[1];
        $ulaw .= chr(linearToUlaw($sample));
    }

    return $ulaw;
}
function linearToUlaw(int $sample): int {
    $BIASED_CLIP = 32635;
    $BIAS = 0x84;
    $CLIP = 8159;

    $sign = ($sample < 0) ? 0x80 : 0x00;
    if ($sample < 0) {
        $sample = -$sample;
        if ($sample > $BIASED_CLIP) $sample = $BIASED_CLIP;
    } else {
        if ($sample > $BIASED_CLIP) $sample = $BIASED_CLIP;
    }

    $sample += $BIAS;

    $exponent = 7;
    $expMask = 0x4000;
    for (; ($sample & $expMask) == 0 && $exponent > 0; $exponent--, $expMask >>= 1);

    $mantissa = ($sample >> (($exponent == 0) ? 4 : ($exponent + 3))) & 0x0F;
    $ulawByte = ~($sign | ($exponent << 4) | $mantissa) & 0xFF;

    return $ulawByte;
}

