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
        $domain = getenv('SIP_DOMAIN') ?: 'spechshop.com';
        $host = gethostbyname($domain);
        $phone = new trunkController($username, $password, $host, 5060);
        if (!$phone->register(2)) {
            throw new \Exception("Erro ao registrar");
        }
        $phone->defineTimeout(120);
        $audioBuffer = '';
        $phone->onRinging(function ($phone) {
            cli::pcl("Chamada recebida", "yellow");
        });
        $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {
            cli::pcl("Chamada finalizada", "red");
            $phone->saveBufferToWavFile('gravado.wav', $audioBuffer);
            $phone->close();
        });
        $phone->mountLineCodecSDP('opus/48000/2');
        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone) use (&$audioBuffer) {
            cli::pcl("Recebendo áudio: " . strlen($pcmData) . " bytes de {$peer['port']} {$phone->codecName}", "blue");
            $audioBuffer .= $pcmData;
        });
        $phone->onAnswer(function (trunkController $phone) {
            $phone->receiveMedia();
            $phone->defineAudioFile('music.wav');
            cli::pcl("Chamada aceita", "green");
            \libspech\Sip\interruptibleSleep(100, $phone->receiveBye);
            $phone->send2833(42017165204, 160);
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