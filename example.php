<?php

include 'plugins/autoloader.php';


\Swoole\Coroutine\run(function () {
    $username = 'lotus';
    $password = '';
    $domain = 'spechshop.com';
    $host = gethostbyname($domain);
    $phone = new trunkController(
        $username,
        $password,
        $host,
        5060,
    );
    if (!$phone->register(2)) {
        throw new \Exception("Erro ao registrar");
    }
    $audioBuffer = '';
    $phone->mountLineCodecSDP('L16/8000');


    $phone->onRinging(function ($call) {

    });

    $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {

        \Plugin\Utils\cli::pcl("Chamada finalizada");
       $pcm = $phone->bufferAudio;
       $phone->saveBufferToWavFile('audio.wav', $pcm);

    });


    $phone->onAnswer(function ($call) {

    });


    $phone->onReceiveAudio(function ($pcmData, $peer) use (&$audioBuffer) {

        $audioBuffer .= $pcmData;
    });
    $phone->onBeforeAudioBuild(function ($data) {
        \Plugin\Utils\cli::pcl(strlen($data) . " bytes de áudio");
        return $data;
    });


    $phone->prefix = 4479;
    $phone->call('551140040104');



});