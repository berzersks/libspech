<?php

namespace plugins\Start;

use ObjectProxy;
use plugins\Extension\plugins;
use Swoole\Coroutine;
use Swoole\Timer;

class handler
{
    public static function callback(\Swoole\Http\Server $server): void
    {
        if (cache::global()['interface']['server']['autoGenerateSslCertificate']) $prefix = 'https://';
        else $prefix = 'http://';
        print (new \plugins\Start\console())->color(sprintf("running in: => {$prefix}%s:%s%s", $server->host, $server->port, PHP_EOL), 'yellow');
        while (true) {
            $times = self::getFilesTime();
            Coroutine::sleep(1);
            $newTimes = self::getFilesTime();


            $diff = array_diff_assoc($newTimes, $times);
            if (!empty($diff)) {
                $server->stop();
                $server->shutdown();
                return;
            }
        }

    }

    public static function getFilesTime(): array
    {
        $coroutines = [];
        $tokensState = [];
        $csf = plugins::listFiles('.');
        $reloadCaseFileModify = cache::global()['interface']['reloadCaseFileModify'];
        foreach ($csf as $file) $coroutines[] = Coroutine::create(function () use ($file, &$tokensState, $reloadCaseFileModify) {
            $type = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($type, $reloadCaseFileModify))
                if (!str_contains($file, 'captured/') && !str_contains($file, 'cookies/')) {
                    $tokensState[$file] = filemtime($file);
                }
        });
        Coroutine::join($coroutines);
        return $tokensState;
    }
}