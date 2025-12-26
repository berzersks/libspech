<?php

/**
 * libspech - Example Usage
 *
 * Copyright (c) 2025 Lotus / berzersks
 * Website: https://spechshop.com
 * All Rights Reserved.
 *
 * PROPRIETARY SOFTWARE - Unauthorized use is prohibited.
 * Please respect the creator. See LICENSE for terms.
 */
ini_set('memory_limit', '1024M');

use libspech\Cli\cli;
use libspech\Sip\trunkController;

\Swoole\Runtime::enableCoroutine();
include 'plugins/autoloader.php';
\Swoole\Coroutine\run(function () {
    \Swoole\Coroutine::create(function () {
        $username = getenv('SIP_USERNAME') ?: '';
        $password = getenv('SIP_PASSWORD') ?: '';
        $domain = getenv('SIP_HOST') ?: 'spechshop.com';
         if (!filter_var($domain, FILTER_VALIDATE_IP)) {
            $host = gethostbyname($domain);
        } else {
            $host = $domain;
        }
        $phone = new trunkController($username, $password, $host);

        if (!$phone->register(10)) {
            throw new \Exception("Erro ao registrar");
        }


        $phone->defineTimeout(120);
        $audioBuffer = '';
        $phone->onRinging(function ($phone) {
            cli::pcl("Chamada recebida", "yellow");
        });
        $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {
            cli::pcl("Chamada finalizada", "red");
            $opus = new opusChannel(48000, 1);
            $opus->setBitrate(8000);
            $opus->setComplexity(8);
            $opus->setVBR(true);
            $spacial = '';
            foreach (str_split($audioBuffer, $phone->frequencyCall / 25) as $chunk) {



                $chunk = resampler($chunk, $phone->frequencyCall, 48000);
                $spacial .= $opus->spatialStereoEnhance($chunk, 1.0, 0.5);
                //$spacial .= $opus->decode($sp, 48000);
            }


            $head = \libspech\Sip\waveHead3(
                strlen($spacial),
                48000,
                2
            );
            $opus->destroy();

            file_put_contents('rec.wav', $head . $spacial);
            $phone->close();
        });
        $phone->mountLineCodecSDP('PCMU/8000');
        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone) use (&$audioBuffer) {
  
            

            // optional
//            $audioBuffer .= $pcmData;
        });
        $phone->onAnswer(function (trunkController $phone) {
            $phone->receiveMedia();
            $phone->defineAudioFile('music.wav');
            cli::pcl("Chamada aceita", "green");
            \libspech\Sip\interruptibleSleep(100, $phone->receiveBye);
            $phone->send2833('*', 160);
            $phone->send2833(999999999, 160);
            \libspech\Sip\interruptibleSleep(30, $phone->receiveBye);
        });
        $phone->onKeyPress(function ($event, $peer) use ($phone) {
            cli::pcl("Digitando: " . $event, "yellow");
        });
        $phone->call('551140040104');


        cli::pcl("Script finalizado", "green");
        cli::pcl("Processo cancelado", "red");
        $phone->close();
    });
});
cli::pcl("Processo encerrado com sucesso", "green");