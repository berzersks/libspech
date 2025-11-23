<?php

namespace plugins\Extension;

class plugins
{
    public static function baseDir(): ?string
    {
        return explode('plugins', __DIR__)[0];
    }

    public static function listFiles($dir)
    {
        $result = [];
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === "." || $file === "..") {
                continue;
            }
            $filePath = $dir . '/' . $file;
            if (is_dir($filePath)) {
                $result = array_merge($result, self::listFiles($filePath));
            } else {
                $result[] = $filePath;
            }
        }
        return $result;
    }

    public static function getHost(string $link): ?string
    {
        return explode(':', explode("//", $link)[1])[0];
    }

    public static function buildCookie($requestCookie): ?string
    {
        if (!empty($requestCookie)) {
            $cookies = [];
            foreach ($requestCookie as $name => $value) $cookies[] = $name . '=' . $value;
            return implode('; ', $cookies);
        } else return null;
    }

    public static function headerParser(array $headers): ?array
    {
        $header = [];
        foreach ($headers as $key => $value) {
            $break = false;
            $headerLine = sprintf("%s: %s", $key, $value);
            $notAllowed = ['accept-enc', 'upgrade-insecure-requests', 'cache-control', 'keep-alive', 'content-length', 'pragma', 'Pragma', 'max-age', 'sec-'];
            foreach ($notAllowed as $search) {
                if (preg_match("/{$search}/i", $headerLine)) $break = true;
            }
            if (!$break) $header[] = sprintf("%s: %s", $key, $value);
        }
        return $header;
    }

    public static function getLastHeaders(string $responseBody, int $length): ?array
    {
        $headers = [];
        $lastMount = substr($responseBody, 0, $length);
        foreach (explode(PHP_EOL, $lastMount) as $k => $v)
            if (strpos($v, ': ') !== false)
                $headers[trim(explode(': ', $v)[0])] = trim(explode(': ', $v)[1]);
        return [
            'headers' => $headers,
            'response' => substr($responseBody, $length)
        ];
    }

    public static function getString($stringBody, $startDelimiter, $endOfDelimiter): ?string
    {
        return @explode($endOfDelimiter, explode($startDelimiter, $stringBody)[1])[0];
    }

    public static function checkDocument($documentNumber): ?bool
    {
        $documentNumber = preg_replace('/[^0-9]/is', '', $documentNumber);
        if (strlen($documentNumber) != 11) {
            return false;
        }
        if (preg_match('/(\d)\1{10}/', $documentNumber)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $documentNumber[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($documentNumber[$c] != $d) {
                return false;
            }
        }
        return true;
    }
}
