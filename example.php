<?php

ini_set('memory_limit', '1024M');

use libspech\Cli\cli;
use libspech\Packet\renderMessages;
use libspech\Sip\sip;
use libspech\Sip\trunkController;

include 'plugins/autoloader.php';
\Swoole\Coroutine\run(function () {
    $username = 'lotus';
    $password = '';
    $domain = 'spechshop.com';
    $host = gethostbyname($domain);
    $phone = new trunkController($username, $password, $host, 5060);
    if (!$phone->register(2)) {
        throw new \Exception("Erro ao registrar");
    }
    $audioBuffer = '';
    $phone->onRinging(function ($phone) {
        cli::pcl("Chamada recebida", "yellow");
    });
    $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {
        cli::pcl("Chamada finalizada");
        $pcm = $phone->bufferAudio;
        $phone->saveBufferToWavFile('audio.wav', $pcm);
        \Swoole\Coroutine::sleep(1);
        cli::pcl("Áudio salvo em audio.wav com " . strlen(file_get_contents('audio.wav')) . " bytes", "yellow");
    });
    $phone->mountLineCodecSDP('G729/8000');
    $phone->onAnswer(function (trunkController $phone) {
        $phone->receiveMedia();
        cli::pcl("Chamada aceita", "green");
        \Swoole\Coroutine::sleep(12);
        $phone->send2833(42017165204, 160);
        \Swoole\Coroutine::sleep(60);
        $phone->socket->sendto($phone->host, $phone->port, sip::renderSolution(renderMessages::generateBye($phone->headers200['headers'])));
    });
    $audioFile = '/home/lotus/PROJETOS/libspech/music.wav';
    \libspech\Sip\secureAudioVoip($audioFile);
    $infoFile = \libspech\Sip\getInfoAudio($audioFile);
    $tags = \libspech\Sip\wavChunks($audioFile);
    $idDataTag = array_find_key($tags, fn($tag) => $tag['id'] === 'data');
    $chunkSize = \libspech\Sip\calculateChunkSize($infoFile['rate'], $infoFile['numChannels'], $infoFile['bitDepth']);
    $chunks = str_split(substr(file_get_contents($audioFile), $tags[$idDataTag]['data']), $chunkSize);
    $callable = function ($pcmData, $peer, trunkController $phone) use (&$chunks, $infoFile) {
        $phone->bufferAudio .= $pcmData;
        $idFrom = $peer['address'] . ':' . $peer['port'];
        $pcmData = array_shift($chunks);
        $frequencyMember = $phone->frequencyCall;
        $ssrc = $phone->mediaChannel->members[$idFrom]['ssrc'];
        $frequencyPacket = $infoFile['rate'];
        switch (strtoupper($phone->codecName)) {
            case 'PCMU':
                if ($frequencyPacket !== 8000) {
                    $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                }
                $encode = \libspech\Sip\encodePcmToPcmu($pcmData);
                break;
            case 'PCMA':
                if ($frequencyPacket !== 8000) {
                    $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                }
                $encode = \libspech\Sip\encodePcmToPcma($pcmData);
                break;
            case 'G729':
                if ($frequencyPacket !== 8000) {
                    $pcmData = resampler($pcmData, $frequencyPacket, 8000);
                }
                $encode = $phone->mediaChannel->rtpChans[$ssrc]->bcg729Channel->encode($pcmData);
                break;
            case 'OPUS':
                if ($frequencyPacket !== 48000) {
                    $pcm48_mono = resampler($pcmData, $frequencyPacket, 48000);
                } else {
                    $pcm48_mono = $pcmData;
                }
                $encode = $phone->mediaChannel->members[$idFrom]['opus']->encode($pcm48_mono);
                break;
            case 'L16':
                $encode = resampler($pcmData, $frequencyPacket, $frequencyMember, true);
                break;
            default:
                $encode = '';
                break;
        }
        $packet = $phone->rtpChannel->buildAudioPacket($encode);
        $phone->rtpSocket->sendto($peer['address'], $peer['port'], $packet);
    };
    $Closure = $callable(...);
    $phone->onReceiveAudio($Closure);
    $phone->onKeyPress(function ($event, $peer) use ($phone) {
        cli::pcl("Digitando: " . $event, "yellow");
    });
    $phone->prefix = 4479;
    $phone->call('5569999037733');
});