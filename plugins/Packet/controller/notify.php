<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class notify
{
    public static function resolve(\Swoole\Server $socket, $data, $info): bool
    {
        $headers = $data['headers'] ?? [];
        $notify = $headers['NOTIFY'][0] ?? null;
        if (empty($headers['From'])) {
            print cli::color('red', "[notify] Erro: Cabeçalho 'From' está ausente.") . PHP_EOL;
            return false;
        }

        if (empty($notify)) {
            print cli::color('red', "[notify] Erro: Cabeçalho 'notify' está ausente.") . PHP_EOL;
            return false;
        }
        $from = $headers['From'][0] ?? null;
        $uriFrom = sip::extractURI($from);

        $getUser = value($notify, 'sip:', '@') ?? '';
        if (str_contains($getUser, '-')) {
            $getUserParts = explode('-', $getUser);
            $getUser = $getUserParts[0] ?? '';
        }

        if (empty($getUser)) {
            print cli::color('red', "[notify] Erro: Usuário não pôde ser extraído do cabeçalho 'notify'.") . PHP_EOL;
            return false;
        }

        $findConnection = cache::findConnection($getUser);


        $localIp = network::getLocalIp() ?? '127.0.0.1';
        $getByTrunk = sip::getTrunkByUserFromDatabase($getUser);

        if (!$getByTrunk || empty($getByTrunk['account']['u'])) {
            print cli::color('red', "[notify] Erro: Informações do tronco para o usuário '$getUser' não foram encontradas.") . PHP_EOL;
            // pede para o remetente parar de perturbar
            $render = renderMessages::respondForbidden($headers);
            return $socket->sendto($info['address'], $info['port'], $render);
        }

        // Configuração do cabeçalho 'Via'
        $data['headers']['Via'][] = "SIP/2.0/UDP {$info['address']}:{$info['port']};branch=z9hG4bK" . uniqid() . ';extension=' . $getByTrunk['account']['u'];

        // Configuração do cabeçalho 'Contact'
        $contactHeader = $headers['Contact'][0] ?? null;
        if (!$contactHeader) {
            print cli::color('yellow', "[notify] Aviso: Cabeçalho 'Contact' está ausente.") . PHP_EOL;
        } else {
            $uriContact = sip::extractURI($contactHeader);
            $uriContact['user'] = $getUser;
            $uriContact['peer']['host'] = $localIp;
            $uriContact['peer']['port'] = $socket->port ?? 5060;
            $data['headers']['Contact'][0] = sip::renderURI($uriContact);
        }

        // Ajuste do cabeçalho 'Via' principal
        if (!empty($headers['Via'][0])) {
            $vv = explode(':', value($headers['Via'][0], 'SIP/2.0/UDP ', ';branch=') ?? '');
            if (!empty($vv[0]) && !empty($vv[1])) {
                $data['headers']['Via'][0] = str_replace($vv[0] . ':' . $vv[1], $vv[0] . ':5060', $data['headers']['Via'][0]);
            }
        }

        $render = sip::renderSolution($data);

        // Gerenciamento de dados RTP
        $callId = $headers['Call-ID'][0] ?? null;
        if (!$callId) {
            print cli::color('yellow', "[notify] Aviso: Call-ID está ausente.") . PHP_EOL;
        } else {
            $rtpData = cache::loadRTPData();
            if (!empty($rtpData[$callId])) {
                cache::deleteRTPData($callId);
                print cli::color('green', "[notify] Dados RTP removidos para Call-ID: $callId.") . PHP_EOL;
            }
        }

        // Log e envio do pacote notify


        print cli::color('blue', "[notify] notify enviado para $getUser em $findConnection[0]:$findConnection[1].") . PHP_EOL;
        $socket->sendto($info['address'], $info['port'], renderMessages::respondOptions($headers));
        return $socket->sendto($findConnection['address'], $findConnection['port'], $render);
    }
}
