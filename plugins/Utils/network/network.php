<?php

namespace plugin\Utils;

use plugins\Utils\cache;
use Swoole\Coroutine\Socket;

class network
{
    private static int $minPort = 10000;
    private static int $maxPort = 62000;

    public static function getLocalIp(): ?string
    {
        $localAddress = '0.0.0.0';
        if (!empty(cache::get('myIpAddress'))) return cache::get('myIpAddress');
        foreach (swoole_get_local_ip() as $localAddress) {
            if (!empty(filter_var($localAddress, FILTER_VALIDATE_IP))) {
                break;
            }
        }
        return $localAddress;
    }

    public static function isPrivateIp(?string $ip): bool
    {
        if (empty($ip)) {
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $privateRanges = ["10.0.0.0|10.255.255.255", "172.16.0.0|172.31.255.255", "192.168.0.0|192.168.255.255"];
            foreach ($privateRanges as $range) {
                [$start, $end] = explode("|", $range);
                if (ip2long($ip) >= ip2long($start) && ip2long($ip) <= ip2long($end)) {
                    return true;
                }
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if (str_starts_with($ip, "fc") || str_starts_with($ip, "fd")) {
                return true;
            }
        }
        return false;
    }

    public static function isPublicIp(?string $ip): bool
    {
        return !self::isPrivateIp($ip);
    }

    public static function getFreePort($type = 'udp'): ?int
    {
        $maxAttempts = 100;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $port = rand(self::$minPort, self::$maxPort);
            if (self::isPortAvailable($port, $type)) {
                return $port;
            }
        }
        print cli::cl('red', 'Could not find a free port, retrying...');
        return self::getFreePort();
    }

    public static function isPortAvailable(int $port, $type = 'udp'): bool
    {
        $socket = new Socket(AF_INET, SOCK_DGRAM, 0);
        $result = $socket->bind($type === 'udp' ? '0.0.0.0' : '', $port);
        $socket->close();
        return $result;
    }

    /** retorna o ip resolvido caso seja 127.0.0.1 por exemplo, retornará 10.0.2.6 */
    public static function resolveAddress(mixed $address): ?string
    {
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            return $address;
        }

        // Remove URL scheme/path and get just the host
        $host = preg_replace('#^(?:https?://)?([^/]+)/?.*$#', '$1', $address);

        // Try DNS lookup
        $ip = @dns_get_record($host, DNS_A)[0]['ip'] ?? null;
        return $ip ?: null;
    }
}
