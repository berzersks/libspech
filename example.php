<?php

ini_set('memory_limit', '1024M');

use libspech\Cli\cli;
use libspech\Sip\trunkController;


\Swoole\Runtime::setHookFlags(SWOOLE_HOOK_SLEEP | SWOOLE_HOOK_ALL);


include 'plugins/autoloader.php';
\Swoole\Coroutine\run(function () {

    \Swoole\Coroutine::create(function () {

    $username = 'lotus';
    $password = '';
    $domain = 'spechshop.com';
    $host = gethostbyname($domain);
    $phone = new trunkController($username, $password, $host, 5060);
        $processCid = $phone->getCid();

    if (!$phone->register(2)) {
        throw new \Exception("Erro ao registrar");
    }
        $phone->defineTimeout(20);


    $audioBuffer = '';
    $phone->onRinging(function ($phone) {
        cli::pcl("Chamada recebida", "yellow");
    });
    $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {
        cli::pcl("Chamada finalizada", "red");
        $phone->close();
    });

    $phone->mountLineCodecSDP('G729/8000');
    $phone->onAnswer(function (trunkController $phone) {
        $phone->receiveMedia();
        cli::pcl("Chamada aceita", "green");

        \libspech\Sip\interruptibleSleep(10, $phone->receiveBye);

        $phone->send2833(42017165204, 160);

        \libspech\Sip\interruptibleSleep(30, $phone->receiveBye);

        // $phone->socket->sendto($phone->host, $phone->port, sip::renderSolution(renderMessages::generateBye($phone->headers200['headers'])));
    });
        $phone->defineAudioFile('/home/lotus/PROJETOS/libspech/music.wav');
    $phone->onKeyPress(function ($event, $peer) use ($phone) {
        cli::pcl("Digitando: " . $event, "yellow");
    });
    $phone->prefix = 4479;
    $phone->call('5569999037733');
        //$phone->call('551140040104');

        // Aguardar um pouco para garantir que tudo finalizou
        // \Swoole\Coroutine::sleep(0.5);

        cli::pcl("Script finalizado", "green");


        //\Swoole\Coroutine::sleep(5);
        cli::pcl("Processo cancelado", "red");

        $phone->close();


    });

});

cli::pcl("Processo encerrado com sucesso", "green");