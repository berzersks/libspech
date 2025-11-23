<?php

use Plugin\Utils\cli;
use plugins\Utils\cache;
use Swoole\Server;

class media
{
    public static function record(string $data): void
    {
        $flags = ord($data[0]);
        $realData = explode('__::__', substr($data, 1), 3);
        $rtp = new rtpc($realData[0]); // 🎯 parser direto
        $codec = $rtp->getCodec();

        $callId = $realData[1];

        $username = $realData[2];

        /** @var ObjectProxy $bp */
        $bp = \plugins\Utils\cache::get('bufferProxy');
        if (!$bp->isset($callId)) $bp->set($callId, new AudioProxyBuffer);

        /** @var AudioProxyBuffer $audioProxyBuffer */
        $audioProxyBuffer = $bp->get($callId);
        if ($codec == 18) {
            if (!$audioProxyBuffer->channelAvaliable()) $audioProxyBuffer->setChannel(new bcg729Channel);
        }

        $decoded = match ((int)$codec) {
            8 => decodePcmaToPcm($rtp->payloadRaw),
            0 => decodePcmuToPcm($rtp->payloadRaw),
            18 => $audioProxyBuffer->channel->decode($rtp->payloadRaw),
            default => $rtp->payloadRaw
        };
        $audioProxyBuffer->appendkey($username, $decoded);
        // cli::pcl("$username: " . $audioProxyBuffer->length($username), 'cyan');


        // destruir via global
        cache::unset('bufferProxy', $callId);



    }

    public static function volume(string $data)
    {
        $e = explode('__::__', $data, 3);
        if (count($e) != 3) return;
        $volume = (int)$e[0];
        $callId = $e[1];
        $username = $e[2];


        $volumes = cache::get('volumes');
        if (!is_array($volumes)) {
            cache::subDefine('volumes', $callId, [
                $username => $volume
            ]);
        }


        $vcid = @$volumes[$callId];
        if (!is_array($vcid)) $vcid = [];
        $diffUser = '';
        $diffv = 0;
        foreach ($vcid as $u => $v) {
            if ($u != $username) {
                $diffUser = $u;
                $diffv = $v;
                break;
            }
        }





        cache::subDefine('volumes', $callId, [
            $username => $volume,
            $diffUser => $diffv
        ]);
    }

}