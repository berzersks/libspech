<?php

namespace handlers;

use Plugin\Utils\cli;
use plugin\Utils\network;
use ObjectProxy;
use plugins\Utils\cache;
use sip;
use Swoole\Server;
use Swoole\Timer;
use trunkController;
use function Swoole\Coroutine\Http\get;

class refer
{
    public static function resolve(\ServerSocket $socket, $data, $info): bool
    {
        if (method_exists('\trunk\refer', 'resolve')) {
            return call_user_func('\trunk\refer::resolve', $socket, $data, $info);
        }

        $headers = $data['headers'];
        $refer = $headers['REFER'][0] ?? null;

        if (empty($headers['Call-ID'])) {
            print cli::color('red', "[refer] Erro: Cabeçalho 'Call-ID' está ausente.") . PHP_EOL;
            return false;
        }
        if (empty($headers['From'])) {
            print cli::color('red', "[refer] Erro: Cabeçalho 'From' está ausente.") . PHP_EOL;
            return false;
        }
        if (empty($refer)) {
            print cli::color('red', "[refer] Erro: Cabeçalho 'refer' está ausente.") . PHP_EOL;
            return false;
        }
        $callId = $headers['Call-ID'][0];
        $traces = cache::get("traces");
        if (!array_key_exists($callId, $traces)) {
            cache::subDefine("traces", $callId, []);
        }
        $tpc = json_decode($socket->tpc->get($callId, 'data'), true);
        $tpc['refer'] = true;
        $socket->tpc->set($callId, ['data' => json_encode($tpc)]);


        $uriFrom = sip::extractUri($headers['From'][0]);
        cache::subJoin('traces', $callId, [
            "direction" => "received",
            "receiveFrom" => trunkController::renderURI(["user" => $uriFrom["user"], "peer" => ["host" => $info["address"], "port" => $info["port"]]]),
            "data" => $data,
        ]);

        /** @var ObjectProxy $sop */
        $sop = cache::global()['swooleObjectProxy'];
        if ($sop->isset($callId)) {
            /** @var \trunkController $trunkController */
            $trunkController = $sop->get($callId);
            if ($trunkController->headers200) {
                if (method_exists('\trunk\refer', 'resolve')) {
                    return call_user_func('\trunk\refer::resolve', $socket, $data, $info);
                } else
                    return $socket->sendto($info['address'], $info['port'], renderMessages::respondForbidden($headers));
            }

        }


        $from = $headers['From'][0] ?? null;

        $uriContact = sip::extractURI($headers['Contact'][0]);
        $uriFrom = sip::extractURI($from);
        $uriTo = sip::extractURI($headers['To'][0]);
        if (array_key_exists('Referred-By', $headers)) $rb = $headers['Referred-By'][0];
        else $rb = $headers['Referred-by'][0];
        $referBy = sip::extractURI($rb);
        $localIp = network::getLocalIp();

        $getUser = value($refer, 'sip:', '@') ?? '';
        if (str_contains($getUser, '-')) {
            $getUserParts = explode('-', $getUser);
            $getUser = $getUserParts[0] ?? '';
        }


        $cacheTags = [
            'to' => $uriTo,
            'from' => $uriFrom,
            'vias' => $headers['Via'],
        ];

        $uriFrom['user'] = $getUser;
        $uriFrom['extra'] = false;
        $referTo = value($headers['Refer-To'][0], 'sip:', '@');
        $uriFromRender = sip::renderURI($uriFrom);
        $uriTo['user'] = $referTo;
        $uriTo['extra'] = false;
        $uriTo['additional'] = false;
        $uriToRender = sip::renderURI($uriTo);
        $toTag = value($headers['To'][0], 'tag=', PHP_EOL);
        $fromTag = value($headers['From'][0], 'tag=', PHP_EOL);
        $headers['Via'][] = $headers['Via'][0] . ";to-tag=$toTag;from-tag=$fromTag";


        $callId = trim($headers['Call-ID'][0]);
        $dialogProxy = cache::global()['dialogProxy'];
        $rules = cache::global()['rules'];
        $rules[$callId] = $referBy['user'];
        $sessionFound = $dialogProxy[$callId][$uriFrom['user']];
        if (!$sessionFound['proxyPort']) {
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondUserNotFound($headers));
        }


        if (!array_key_exists($callId, $dialogProxy)) {
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondUserNotFound($headers));
        }


        cache::define('rules', $rules);
        $notifyModel = [
            "method" => "NOTIFY",
            "methodForParser" => "NOTIFY sip:$uriContact[user]@{$uriContact['peer']['host']}:{$uriContact['peer']['port']} SIP/2.0",
            "headers" => [
                "NOTIFY" => ["sip:$uriContact[user]@{$uriContact['peer']['host']}:{$uriContact['peer']['port']} SIP/2.0"],
                "Via" => $headers['Via'],
                "Max-Forwards" => ['70'],
                "From" => $headers['To'],
                "To" => $headers['From'],
                "Call-ID" => [$headers['Call-ID'][0]],
                "CSeq" => [(intval($headers['CSeq'][0]) + 1) . " NOTIFY"], // Incrementar sequência
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Event" => ["refer;id=" . intval($headers['CSeq'][0])],
                "Subscription-state" => ["terminated;reason=noresource"],
                "Content-Type" => ["message/sipfrag;version=2.0"],
                "Allow" => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE'],
                "Supported" => ['replaces, timer'],
                "Content-Length" => ["0"],
            ],
            'body' => 'SIP/2.0 200 OK'
        ];
        $socket->sendto($info['address'], $info['port'], renderMessages::respond202Accepted($headers));
        $socket->sendto($info['address'], $info['port'], sip::renderSolution($notifyModel));
        // Construir e enviar o BYE para o cliente que enviou o REFER


        $headers['Via'][1] = sip::teachVia($uriFrom['user'], $info) . ';refer';
        $tags = cache::global()['tags'];
        $tags[$callId] = $cacheTags;
        cache::define('tags', $tags);


        $inviteHeaders = [
            "method" => "INVITE",
            "methodForParser" => "INVITE sip:$referTo@$localIp SIP/2.0",
            "headers" => [
                "INVITE" => ["sip:$referTo@$localIp SIP/2.0"],
                "Via" => $headers['Via'],
                "Max-Forwards" => ['70'],
                "From" => [$uriFromRender],
                "To" => [$uriToRender],
                "Call-ID" => $headers['Call-ID'],
                "CSeq" => [intval($headers['CSeq'][0]) . " INVITE"],
                "Contact" => ["<sip:$uriFrom[user]@$localIp:5060>"],
                "Allow" => ['INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, INFO, PUBLISH, MESSAGE'],
                "Supported" => ['replaces, timer'],
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Session-Expires" => ["1800"],
                "Min-SE" => ["90"],
                "Content-Type" => ["application/sdp"],
            ],
            "sdp" => [
                'v' => ['0'],
                'o' => ["root 1561393747 1561393747 IN IP4 $localIp"],
                's' => ['Transfer Call'],
                'c' => ["IN IP4 {$sessionFound['proxyIp']}"],
                't' => ['0 0'],
                'm' => ["audio {$sessionFound['proxyPort']} RTP/AVP 0 8 101"],
                'a' => [
                    'rtpmap:0 PCMU/8000',
                    'rtpmap:8 PCMA/8000',
                    'rtpmap:101 telephone-event/8000',
                    'fmtp:101 0-15',
                    'sendrecv',
                ],
            ],
        ];


        $renderInvite = sip::renderSolution($inviteHeaders);
        $findConnection = cache::findConnection($referTo);
        if ($findConnection === null) {
            print cli::color('red', "Erro: Conexão não encontrada para referTo $referTo") . PHP_EOL;
            return $socket->sendto($info['address'], $info['port'], renderMessages::respondUserNotFound($inviteHeaders));
        }
        $byeHeaders = [
            "method" => "BYE",
            "methodForParser" => "BYE sip:{$headers['Contact'][0]} SIP/2.0",
            "headers" => [
                "Via" => $headers['Via'],
                "Max-Forwards" => ['70'],
                "From" => $headers['From'],
                "To" => $headers['To'],
                "Call-ID" => $headers['Call-ID'],
                "CSeq" => [(intval($headers['CSeq'][0]) + 1) . " BYE"],
                "Content-Length" => [0],
            ],
        ];

        $renderedBye = sip::renderSolution($byeHeaders);
        $socket->sendto($info['address'], $info['port'], $renderedBye);
        $dialogProxy = cache::global()['dialogProxy'];

        $dialogProxy[$callId][$uriFrom['user']]['exclude'] = true;
        $dialogProxy[$callId][$referBy['user']]['referRecorder'] = true;
        $dialogProxy[$callId][$referBy['user']]['username'] = $referTo;
        $dialogProxy[$callId][$referBy['user']]['refer'] = true;
        print cli::color('yellow', "Transferindo {$uriFrom['user']} para $referTo") . PHP_EOL;
        cache::define('dialogProxy', $dialogProxy);
        $hostSerasa = '147.93.67.151';
        $portSerasa = 9502;
        $numberFormat = str_starts_with($uriFrom['user'], '55') ? str_replace('55', '', $uriFrom['user']) : $uriFrom['user'];
        $endPoint = "phone/$numberFormat";
        try {
            $response = get("http://$hostSerasa:$portSerasa/$endPoint", ['timeout' => 5])->getBody();
        } catch (\Throwable $e) {
            $response = json_encode(['error' => true]);
        }
        $response = json_decode($response, true);
        if (!empty($response['error'])) {
            $message = "Atenção! infelizmente não encontramos informações para o número $numberFormat, tente procurar por resultados em painéis especializados.";
        } elseif (empty($response[0])) {
            $message = "Atenção! infelizmente não encontramos informações para o número $numberFormat, tente procurar por resultados em painéis especializados.";
        } else {
            $message = "Encontramos as seguintes informações para o número $numberFormat:\n\n";
            foreach ($response as $personal) {
                $message .= "Nome: {$personal['NOME']}\nCPF: {$personal['CPF']}\n\n";
            }
        }
        $smsVoipModel = [
            "method" => "MESSAGE",
            "methodForParser" => "MESSAGE sip:$referTo@$localIp SIP/2.0",
            "headers" => [
                "Via" => $headers['Via'],
                "Max-Forwards" => ['70'],
                'To' => [sip::renderURI([
                    'user' => $referTo,
                    'peer' => [
                        'host' => $localIp,
                        'port' => '5060'
                    ]
                ])],
                'From' => [sip::renderURI([
                    'user' => $uriFrom['user'],
                    'peer' => [
                        'host' => $localIp,
                        'port' => '5060'
                    ],
                    'additional' => [
                        'tag' => uniqid(time())
                    ]
                ])],
                "Call-ID" => [$headers['Call-ID'][0]],
                "CSeq" => [(intval($headers['CSeq'][0]) + 1) . " MESSAGE"], // Incrementar sequência
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Content-Type" => ["text/plain"],
                "Content-Length" => ["0"],
            ],
            'body' => $message
        ];


        $socket->sendto($findConnection['address'], $findConnection['port'], sip::renderSolution($smsVoipModel));
        return $socket->sendto($findConnection['address'], $findConnection['port'], $renderInvite);
    }
}