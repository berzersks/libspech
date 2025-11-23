<?php

namespace handlers;

use Plugin\Utils\cli;
use ServerSocket;
use sip;

class cancel
{
    public static function resolve(ServerSocket $socket, $data, $info): bool
    {
        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($data['headers']));


        $headers = $data['headers'] ?? [];
        $cancel = $headers['CANCEL'][0] ?? null;
        $from = $headers['From'][0] ?? null;
        $to = $headers['To'][0] ?? null;
        $callId = $headers['Call-ID'][0] ?? null;
        if (!$cancel || !$from || !$to || !$callId) {
            print cli::color('red', "[cancel] Erro: Cabeçalhos obrigatórios não foram encontrados.") . PHP_EOL;
            return false;
        }
        $callId = $headers['Call-ID'][0];
        if ($socket->tpc->exist($callId)) {
            $tpc = json_decode($socket->tpc->get($callId, 'data'), true);
            $hangups = $tpc['hangups'] ?? [];
            foreach ($hangups as $userCancel => $hangup) {
                if (array_key_exists('model', $hangup)) {
                    cli::pcl("Enviando cancel para $userCancel {$hangup['info']['host']}:{$hangup['info']['port']}", 'bold_red');
                    $model = $hangup['model'];
                    $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($model));
                }
            }
            \trunkController::resolveCloseCall($callId);


            $socket->tpc->del($callId);
            cli::pcl("Deletando $callId novamente!!!!!!!!", 'bold_red');
            return true;


        }
        cli::pcl("Deletando $callId", 'bold_red');
        return false;

    }
}