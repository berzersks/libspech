<?php

include 'plugins/autoloader.php';


\Swoole\Coroutine\run(function () {
    $username = '';
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


    $phone->onRinging(function ($call) {

    });

    $phone->onHangup(function ($call) {

    });


    $phone->onAnswer(function ($call) {

    });

    $phone->onReceiveAudio(function (...$args) {
        \Plugin\Utils\cli::pcl(strlen($args[0]) . " bytes received");
    });


    $phone->prefix = 4479;
    $phone->call('55114');


});