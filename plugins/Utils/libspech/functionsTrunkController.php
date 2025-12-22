<?php

namespace libspech\Sip;

use Exception;
use Swoole\Coroutine;

function secureAudioVoip(string $filename, bool $forceMono = true): bool
{
    $raw = file_get_contents($filename);
    if (!$raw) {
        throw new Exception("Não foi possível ler o arquivo: $filename");
    }

    // -----------------------------
    // 1) HEADER RIFF/WAVE
    // -----------------------------
    if (substr($raw, 0, 4) !== "RIFF" || substr($raw, 8, 4) !== "WAVE") {
        return false; // ignora
    }

    $len = strlen($raw);
    $pos = 12;

    $fmtChunk = null;
    $dataOffset = null;
    $dataSize = null;

    // -----------------------------
    // 2) PERCORRER CHUNKS
    // -----------------------------
    while ($pos + 8 <= $len) {
        $id = substr($raw, $pos, 4);
        $size = unpack("V", substr($raw, $pos + 4, 4))[1];
        $chunkData = $pos + 8;

        if ($id === "fmt ") {
            $fmtChunk = substr($raw, $chunkData, $size);
        } elseif ($id === "data") {
            $dataOffset = $chunkData;
            $dataSize = $size;
            break;
        }

        $pos += 8 + $size;
        if ($pos % 2 === 1) $pos++;
    }

    if (!$fmtChunk || !$dataOffset) {
        return false;
    }

    // -----------------------------
    // 3) Ler info do fmt
    // -----------------------------
    $audioFormat = unpack("v", substr($fmtChunk, 0, 2))[1];
    $channels = unpack("v", substr($fmtChunk, 2, 2))[1];
    $sampleRate = unpack("V", substr($fmtChunk, 4, 4))[1];
    $bits = unpack("v", substr($fmtChunk, 14, 2))[1];

    // -----------------------------
    // 4) Decisão inteligente
    // -----------------------------

    // Arquivo já está no formato correto → NÃO faz nada
    if (
        $audioFormat === 1 &&   // PCM
        $bits == 16 &&          // 16-bit
        $channels == 1 &&       // mono
        $sampleRate == 48000    // 48kHz
    ) {
        return false;
    }

    // Normal WAV sem chunks extras → não é "arquivo chato"
    if ($dataOffset === 44) {
        return false;
    }

    // Apenas converte se for dos "chatos"
    $isBad =
        ($audioFormat == 1) &&
        ($bits == 24) &&
        ($channels == 2) &&
        ($sampleRate == 44100) &&
        ($dataOffset > 44);

    if (!$isBad) {
        return false;
    }

    // --------------------------------------------------
    // COMEÇA A CONVERSÃO (“arquivo chato” detectado)
    // --------------------------------------------------

    $pcm = substr($raw, $dataOffset, $dataSize);
    $frameSize = 3 * $channels;
    $totalFrames = intdiv(strlen($pcm), $frameSize);

    // Helper 24-bit → float32
    $read24 = function (string $s, int $offset): float {
        $b0 = ord($s[$offset]);
        $b1 = ord($s[$offset + 1]);
        $b2 = ord($s[$offset + 2]);
        $u = $b0 | ($b1 << 8) | ($b2 << 16);
        if ($u & 0x800000) $u -= 0x1000000;
        return $u / 8388608.0;
    };

    // -----------------------------
    // 5) MONO MIX
    // -----------------------------
    $mono = [];
    $peak = 0.0;

    for ($i = 0; $i < $totalFrames; $i++) {
        $off = $i * $frameSize;
        $l = $read24($pcm, $off);
        $r = ($channels > 1) ? $read24($pcm, $off + 3) : $l;

        $s = $forceMono ? (($l + $r) * 0.5) : $l;

        $peak = max($peak, abs($s));
        $mono[] = $s;
    }

    // -----------------------------
    // 6) NORMALIZAÇÃO (prevent clipping)
    // -----------------------------
    $targetPeak = 0.98;
    $gain = ($peak > $targetPeak) ? ($targetPeak / $peak) : 1.0;

    foreach ($mono as &$f) $f *= $gain;
    unset($f);

    // -----------------------------
    // 7) RESAMPLE 44.1k → 48k
    // -----------------------------
    $rateIn = 44100;
    $rateOut = 48000;

    $ratio = $rateIn / $rateOut;
    $outFrames = (int)floor(count($mono) * ($rateOut / $rateIn));
    $out = [];

    for ($n = 0; $n < $outFrames; $n++) {
        $src = $n * $ratio;
        $i0 = (int)$src;
        $i1 = min($i0 + 1, count($mono) - 1);
        $frac = $src - $i0;
        $out[] = $mono[$i0] + ($mono[$i1] - $mono[$i0]) * $frac;
    }

    // -----------------------------
    // 8) FLOAT → INT16
    // -----------------------------
    $pcmOut = "";
    foreach ($out as $f) {
        if ($f > 1.0) $f = 1.0;
        if ($f < -1.0) $f = -1.0;
        $val = (int)round($f * 32767);
        $pcmOut .= pack("v", $val & 0xFFFF);
    }

    // -----------------------------
    // 9) HEADER WAV 16-bit mono 48kHz
    // -----------------------------
    $byteRate = $rateOut * 2;
    $blockAlign = 2;

    $header =
        pack("A4V", "RIFF", 36 + strlen($pcmOut)) .
        "WAVE" .
        pack("A4VvvVVvv",
            "fmt ", 16, 1, 1,
            $rateOut,
            $byteRate,
            $blockAlign,
            16
        ) .
        pack("A4V", "data", strlen($pcmOut));

    // -----------------------------
    // 10) Sobrescrever o original
    // -----------------------------
    file_put_contents($filename, $header . $pcmOut);

    return true;
}

function wavChunks(string $file)
{
    $raw = file_get_contents($file);
    if (!$raw) {
        die("Erro ao ler arquivo.\n");
    }

    if (substr($raw, 0, 4) !== "RIFF" || substr($raw, 8, 4) !== "WAVE") {
        die("Não é WAV válido.\n");
    }


    $len = strlen($raw);

    // Começa após "RIFF + size + WAVE"
    $pos = 12;

    $chunks = [];

    while ($pos + 8 <= $len) {

        $id = substr($raw, $pos, 4);
        $size = unpack("V", substr($raw, $pos + 4, 4))[1];

        $start = $pos;
        $dataStart = $pos + 8;
        $dataEnd = $dataStart + $size;


        if ($dataEnd > $len) {

        }

        $chunks[] = [
            'id' => $id,
            'start' => $start,
            'size' => $size,
            'data' => $dataStart,
            'end' => $dataEnd,
        ];

        // pula chunk
        $pos = $dataEnd;

        // alinhamento para byte par
        if ($pos % 2 === 1) {
            $pos++;

        }

        if ($pos >= $len) break;
    }


    foreach ($chunks as $c) {

    }

    return $chunks;
}


function getInfoAudio(string $filename): array
{
    $raw = file_get_contents($filename);
    $infoMedia = substr(file_get_contents($filename), 0, 44);
    $sampleRate = unpack("V", substr($infoMedia, 24, 4))[1];
    $numChannels = unpack("v", substr($infoMedia, 22, 2))[1];
    $bitDepth = unpack("v", substr($infoMedia, 34, 2))[1];
    return [
        'rate' => $sampleRate,
        'numChannels' => $numChannels,
        'bitDepth' => $bitDepth
    ];
}

/**
 * Calcula o tamanho do chunk PCM para um determinado sample rate e duração
 *
 * @param int $sampleRate Taxa de amostragem (Hz)
 * @param int $channels Número de canais (1=mono, 2=stereo)
 * @param int $bitsPerSample Bits por sample (8, 16, 24, 32)
 * @param float $durationMs Duração em milissegundos (padrão: 20ms)
 * @return int Tamanho do chunk em bytes
 *
 * Exemplos:
 * - 8kHz, mono, 16-bit, 20ms = 320 bytes
 * - 16kHz, mono, 16-bit, 20ms = 640 bytes
 * - 48kHz, mono, 16-bit, 20ms = 1920 bytes
 */
function calculateChunkSize(int $sampleRate, int $channels = 1, int $bitsPerSample = 16, float $durationMs = 20.0): int
{
    $bytesPerSample = $bitsPerSample / 8;
    $samplesNeeded = (int)($sampleRate * ($durationMs / 1000.0));
    return (int)($samplesNeeded * $channels * $bytesPerSample);
}

/**
 * Normaliza o volume de um buffer PCM 16-bit little-endian
 * Útil após resampling para evitar clipping/distorção
 *
 * @param string $pcmData Buffer PCM 16-bit LE
 * @param float $targetPeak Pico alvo (0.0 a 1.0, padrão 0.85 = -1.4dB)
 * @return string PCM normalizado
 */
function normalizePcm(string $pcmData, float $targetPeak = 0.85): string
{
    if (strlen($pcmData) < 2) {
        return $pcmData;
    }

    // Encontrar o pico
    $maxSample = 0;
    $len = strlen($pcmData);

    for ($i = 0; $i < $len; $i += 2) {
        $sample = unpack('s', substr($pcmData, $i, 2))[1];
        $maxSample = max($maxSample, abs($sample));
    }

    // Se não houver sinal ou já estiver normalizado, retorna
    if ($maxSample == 0 || $maxSample <= 32767 * $targetPeak) {
        return $pcmData;
    }

    // Calcular ganho para normalizar
    $gain = (32767 * $targetPeak) / $maxSample;

    // Aplicar ganho
    $normalized = '';
    for ($i = 0; $i < $len; $i += 2) {
        $sample = unpack('s', substr($pcmData, $i, 2))[1];
        $newSample = (int)($sample * $gain);

        // Clamping para evitar overflow
        $newSample = max(-32768, min(32767, $newSample));
        $normalized .= pack('s', $newSample);
    }

    return $normalized;
}

/**
 * Aplica atenuação simples em um buffer PCM (reduz volume)
 *
 * @param string $pcmData Buffer PCM 16-bit LE
 * @param float $gain Ganho (0.0 a 1.0), ex: 0.5 = -6dB
 * @return string PCM atenuado
 */
function attenuatePcm(string $pcmData, float $gain = 0.7): string
{
    if (strlen($pcmData) < 2) {
        return $pcmData;
    }

    $len = strlen($pcmData);
    $attenuated = '';

    for ($i = 0; $i < $len; $i += 2) {
        $sample = unpack('s', substr($pcmData, $i, 2))[1];
        $newSample = (int)($sample * $gain);
        $attenuated .= pack('s', $newSample);
    }

    return $attenuated;
}

function secure_random_bytes(int $length): string
{
    try {
        return random_bytes($length);
    } catch (Exception $e) {
        $fs = '';
        for (; $length--;) $fs .= chr(mt_rand(0, 255));
        return $fs;
    }
}



/**
 * Sleep interrompível que verifica condições a cada 100ms
 * Permite que operações longas sejam interrompidas rapidamente
 *
 * @param int $ms Milissegundos para dormir
 * @param trunkController|null $phone Instância do telefone para verificar closing/error
 * @return bool Retorna true se completou, false se foi interrompido
 */
function interruptibleSleep(float $seconds, &$abort): bool
{
    // Converter para ms apenas para controle interno
    $totalMs = $seconds * 1000;

    // Step de verificação: mínimo absoluto 10ms (0.01s)
    $stepMs = 50; // default: 50ms
    if ($stepMs < 10) {
        $stepMs = 10;
    }

    // Se o total for menor que o step, reduz, mas nunca abaixo de 10ms
    if ($totalMs < $stepMs) {
        $stepMs = max(10, $totalMs);
    }

    $start = microtime(true);

    while (true) {

        if ($abort) {
            return false; // abortou
        }

        // Tempo passado em ms
        $elapsedMs = (microtime(true) - $start) * 1000;

        if ($elapsedMs >= $totalMs) {
            return true; // finalizou normal
        }

        // Quanto falta
        $remainingMs = $totalMs - $elapsedMs;

        // Próximo sleep, respeitando mínimo 10ms sempre
        $nextMs = max(10, min($stepMs, $remainingMs));

        Coroutine::sleep($nextMs / 1000);
    }
}


