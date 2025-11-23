<?php

namespace trunk;

use handlers\renderMessages;
use Plugin\Utils\cli;
use plugins\Utils\cache;
use rpcClient;
use sip;
use Swoole\Coroutine;
use trunkController;

class refer
{
    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {
        cli::pcl(sip::renderSolution($data), 'bold_green');

        $headers = $data["headers"];
        $headers = sip::normalizeArrayKey("Refer-to", "Refer-To", $headers);
        $headers = sip::normalizeArrayKey("Referred-By", "Referred-by", $headers);

        $uriFrom = sip::extractURI($headers["From"][0]);
        $uriTo = sip::extractURI($headers["To"][0]);

        // Validações básicas
        if (empty($headers["Refer-To"])) {
            $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers));
            return true;
        }

        if (empty($headers["Call-ID"][0])) {
            $socket->sendto($info["address"], $info["port"], renderMessages::respondForbidden($headers));
            return true;
        }

        $callId = $headers["Call-ID"][0];
        $referTo = $headers["Refer-To"][0];

        // Carrega TPC da call
        $tpcRaw = $socket->tpc->get($callId, 'data');
        $tpcData = $tpcRaw ? json_decode($tpcRaw, true) : [];

        // RPC → dados de mídia dessa call (proxyPort, ssrc, etc)
        $rpcClient = new rpcClient("127.0.0.1", 9503);
        $rpcRaw = $rpcClient->rpcGet($callId);
        $rpcData = $rpcRaw ? json_decode($rpcRaw, true) : [];

        $toRefer = sip::extractURI($referTo);
        $connectionReferTo = cache::findConnection($toRefer['user']);
        $tpcData = json_decode($socket->tpc->get($callId, 'data'), true);
        if ($tpcData['refer'] == true) {
            $connectionReferTo = null;
        }
        if (!$connectionReferTo) {
            $socket->sendto(
                $info["address"],
                $info["port"],
                renderMessages::respond486Busy($headers, "Destino desconectado")
            );
            return true;
        }

        $uriContact = sip::extractURI($headers['Contact'][0] ?? $headers['From'][0]);

        $cseqNumber = (int)explode(' ', $headers['CSeq'][0])[0];
        $notifyModel = [
            "method" => "NOTIFY",
            "methodForParser" => "NOTIFY sip:{$uriContact['user']}@{$uriContact['peer']['host']}:{$uriContact['peer']['port']} SIP/2.0",
            "headers" => [
                "NOTIFY" => ["sip:{$uriContact['user']}@{$uriContact['peer']['host']}:{$uriContact['peer']['port']} SIP/2.0"],
                "Via" => $headers['Via'],
                "Max-Forwards" => ['70'],
                "From" => [
                    sip::renderURI([
                        'user' => $uriContact['user'],
                        'peer' => [
                            'host' => $uriContact['peer']['host'],
                            'port' => $uriContact['peer']['port']
                        ]
                    ])
                ],
                "To" => $headers['From'],
                "Call-ID" => [$callId],
                "CSeq" => [($cseqNumber + 1) . " NOTIFY"],
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Event" => ["refer;id=" . $cseqNumber],
                "Subscription-state" => ["terminated;reason=accepted"],
                "Content-Type" => ["message/sipfrag;version=2.0"],
                "Allow" => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE'],
                "Supported" => ['replaces, timer'],
            ],
            'body' => 'SIP/2.0 200 OK'
        ];
        $socket->sendto($info['address'], $info['port'], renderMessages::respond202Accepted($headers));
        $socket->sendto($info['address'], $info['port'], sip::renderSolution($notifyModel));

        $trunkController = new \trunkController(
            $toRefer['user'],
            '',
            $connectionReferTo['address'],
            $connectionReferTo['port'],
            $connectionReferTo['address']
        );


        $sop = cache::get('swooleObjectProxy');
        $sop->set($trunkController->callId, $trunkController);

        /** @var trunkController $trunkController */
        $trunkController = $sop->get($trunkController->callId);


        //  $trunkController->socket->connect($connectionReferTo['address'], $connectionReferTo['port']);


        // CallerID: quem está em "To" vira o CLI pro novo destino
        $trunkController->setCallerId($uriTo['user']);
        $trunkController->mountLineCodecSDP('opus/48000/2');
        $trunkController->mountLineCodecSDP('PCMA/8000');
        $trunkController->mountLineCodecSDP('PCMU/8000');



        $trunkController->mountLineCodecSDP('G729/8000/1');
        // Reusa proxyPort + ssrc da call original pro rtpw continuar a mesma sessão
        if (!empty($rpcData['proxyPort'])) {
            $trunkController->audioReceivePort = $rpcData['proxyPort'];
        }
        if (!empty($rpcData['ssrc'])) {

            $trunkController->ssrc = $rpcData['ssrc'];
        }

        // INVITE pro destino referenciado
        $modelInvite = $trunkController->modelInvite($toRefer['user']);
        $modelInvite['methodForParser'] = "INVITE sip:$toRefer[user]@$trunkController->localIp SIP/2.0";


        $modelInvite['headers']['Via'][0] = "SIP/2.0/UDP $trunkController->localIp;branch=z9hG4bK64d" . md5(random_bytes(8)) . ';rport';


        $modelInvite['headers']['From'][0] = sip::renderURI([
            'user' => $uriTo['user'],
            'peer' => [
                'host' => $trunkController->localIp,
                'port' => $socket->__port
            ],
            "additional" => ["tag" => bin2hex(secure_random_bytes(10))],
        ]);
        $modelInvite['headers']['To'][0] = sip::renderURI($toRefer);
        $modelInvite['headers']['Contact'][0] = sip::renderURI([
            'user' => $uriContact['user'],
            'peer' => [
                'host' => $trunkController->localIp,
                'port' => $socket->__port
            ],
            "additional" => ["tag" => bin2hex(secure_random_bytes(10))],
        ]);


        $socket->sendto(
            $connectionReferTo['address'],
            $connectionReferTo['port'],
            sip::renderSolution($modelInvite),
            $info['server_socket']
        );
        $tpcData['refer'] = false;
        $tpcData['deleteCall'] = $callId;
        $tpcData['cancel'] = [
            'info' => $connectionReferTo,
            'model' => $trunkController->getModelCancel($toRefer['user'])
        ];

        $socket->tpc->set($trunkController->callId, [
            'data' => json_encode($tpcData, JSON_PRETTY_PRINT)
        ]);
        // encerrar a call original
        $modelBye = renderMessages::generateBye($data['headers']);
        $socket->sendto($info['address'], $info['port'], sip::renderSolution($modelBye), $info['server_socket']);
        $hangups = $tpcData['hangups'] ?? [];
        if (array_key_exists($uriFrom['user'], $hangups)) {
            $hangup = $hangups[$uriFrom['user']];
            $modelBye = $hangup['model'];
            $socket->sendto($hangup['info']['host'], $hangup['info']['port'], sip::renderSolution($modelBye));
        }

        while ($socket->tpc->exist($callId)) {
            Coroutine::sleep(1);
            cli::pcl("Aguardando deletar {$callId}");
        }
        $inviteHeaders = $modelInvite;
        $cancelHeaders = [
            "method" => "CANCEL",
            "methodForParser" => "CANCEL sip:$toRefer[user]@$trunkController->localIp SIP/2.0",
            "headers" => [
                "Via" => $inviteHeaders["headers"]["Via"],
                "Max-Forwards" => $inviteHeaders["headers"]['Max-Forwards'],
                "From" => $inviteHeaders["headers"]['From'],
                "To" => $inviteHeaders["headers"]['To'],
                "Call-ID" => [$trunkController->callId],
                "CSeq" => ["2 CANCEL"],
                "Contact" => $inviteHeaders["headers"]['Contact'],
                "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, MESSAGE"],
                "Supported" => ["replaces, timer"],
                "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
                "Session-Expires" => ["1800"],
                "Min-SE" => ["90"]
            ]

        ];
        Coroutine::sleep(1);
        $socket->sendto($connectionReferTo['address'], $connectionReferTo['port'], sip::renderSolution($cancelHeaders));
        cli::pcl("Deletando {$trunkController->callId}");
        $tpcData = json_decode($socket->tpc->get($trunkController->callId, 'data'), true);
        if ($tpcData['refer'] !== 'yes') {
            $socket->tpc->del($trunkController->callId);
            cli::pcl("Deletando {$trunkController->callId} novamente!!!!!!!!!!!!!!!");
        } else {
            cli::pcl("Não deletando {$trunkController->callId} pois está referenciando outra call");
        }


        // Aqui deu tudo certo, não manda 486
        return true;
    }


}
