<?php

namespace handlers;

use plugin\Utils\network;
use plugins\Utils\cache;
use sip;

class renderMessages
{
    public static function generateBye(array $headers200)
    {
        $contactUri = [
            'user' => 's',
            'peer' => [
                'host' => network::getLocalIp()
            ]
        ];
        $headers200['Contact'][0] = \sip::renderURI($contactUri);
        $headers200['CSeq'][0] = intval($headers200['CSeq'][0]) + 1 . " BYE";
        $headers200['Content-Length'][0] = "0";
        if (!empty($headers200['Authorization'])) unset($headers200['Authorization']);
        if (!empty($headers200['Proxy-Authorization'])) unset($headers200['Proxy-Authorization']);
        return [
            "method" => "BYE",
            "methodForParser" => "BYE sip:" . \sip::extractUri($headers200['From'][0])['user'] . "@" . network::getLocalIp() . " SIP/2.0",
            "headers" => $headers200
        ];
    }

    public static function respondUserNotFound(array $headers, $optionalMessage = "Tente novamente"): string
    {
        return self::baseResponse($headers, "404", $optionalMessage);
    }

    public static function baseResponse(array $headers, string $statusCode, string $statusMessage, array $additionalHeaders = []): string
    {
        // Processar cabeçalhos Via para manter rport/received se presentes
        $viaHeaders = $headers['Via'];
        if (is_array($viaHeaders) && !empty($viaHeaders[0])) {
            $via = $viaHeaders[0];
            // Manter os parâmetros rport e received se já estiverem presentes
            // Isso garante que as respostas sejam enviadas para o endereço correto em cenários NAT
            $viaHeaders[0] = $via;
        }

        $baseHeaders = [
            "Via" => $viaHeaders,
            "From" => $headers['From'],
            "To" => $headers['To'],
            "Call-ID" => $headers['Call-ID'] ?? ['' . md5(time())],
            "CSeq" => $headers['CSeq'] ?? ['1 ' . $statusCode],
            "Content-Length" => ["0"],
            "Server" => [cache::global()['interface']['server']['serverName']]
        ];

        $response = [
            "method" => $statusCode,
            "methodForParser" => "SIP/2.0 $statusCode $statusMessage",
            "headers" => array_merge($baseHeaders, $additionalHeaders)
        ];

        return \sip::renderSolution($response);
    }

    public static function respondForbidden(array $headers, string $message = "Forbidden"): string
    {
        return self::baseResponse($headers, "403", $message);
    }

    public static function respondOptions(array $headers): string
    {
        $contactUri = [
            'user' => 's',
            'peer' => [
                'host' => network::getLocalIp(),
                'port' => cache::global()['interface']['server']['port']
            ]
        ];
        $headers['Contact'][0] = \sip::renderURI($contactUri);

        $additionalHeaders = [
            "Contact" => [$headers['Contact'][0]],
            "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
            "Supported" => ["replaces, timer"]
        ];
        return self::baseResponse($headers, "200", "OK", $additionalHeaders);
    }

    public static function respond100Trying(array $headers, $statusMessage = 'Trying...'): string
    {
        $additionalHeaders = [
            "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
            "Supported" => ["replaces, timer"]
        ];
        return self::baseResponse($headers, "100", $statusMessage, $additionalHeaders);
    }

    public static function respond202Accepted(array $headers): string
    {
        return self::baseResponse($headers, "202", "Accepted");
    }

    public static function respondAckModel(array $headers): string
    {
        return \sip::renderSolution([
            "method" => "ACK",
            "methodForParser" => "ACK sip:" . \sip::extractUri($headers['From'][0])['user'] . "@" . network::getLocalIp() . " SIP/2.0",
            "headers" => [
                "Via" => $headers['Via'],
                "From" => $headers['From'],
                "To" => $headers['To'],
                "Call-ID" => $headers['Call-ID'],
                "CSeq" => [(intval($headers['CSeq'][0]) ) . " ACK"],
                "Content-Length" => ["0"],
                "Server" => [cache::global()['interface']['server']['serverName']],
                "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
                "Supported" => ["replaces, timer"]
            ]
        ]);
    }


    public static function respond200OK(array $headers, string $body = ""): string
    {
        $contentLength = strlen($body);
        $additionalHeaders = [
            "Content-Length" => [(string)$contentLength]
        ];

        if ($contentLength > 0) {
            $response = [
                "method" => "200",
                "methodForParser" => "SIP/2.0 200 OK",
                "headers" => array_merge($headers, $additionalHeaders),
                "body" => $body
            ];
            return \sip::renderSolution($response);
        }

        return self::baseResponse($headers, "200", "OK");
    }

    public static function modelBye(mixed $byeNumber, mixed $callId, ?string $localIp, mixed $from, mixed $to, mixed $csq, string $authorization)
    {
        return [
            "method" => "BYE",
            "methodForParser" => "BYE sip:$byeNumber@$localIp SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP " . network::getLocalIp() . ";branch=z9hG4bK-" . md5(random_bytes(4))],
                "From" => [$from],
                "To" => [$to],
                "Call-ID" => [$callId],
                "CSeq" => [((int)$csq) + 1 . " BYE"],
                "Max-Forwards" => ["70"],
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Allow" => ["INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"],
                "Authorization" => [$authorization],
                "Content-Length" => ["0"],
            ],
        ];
    }

    public static function respond486Busy(mixed $backupHeaders, $message = "Busy Here"): string
    {
        return self::baseResponse($backupHeaders, "403", "Busy Here");
    }

    public static function respond487RequestTerminated(mixed $backupHeaders)
    {
        return self::baseResponse($backupHeaders, "487", "Request Terminated");
    }

    public static function e503Un(mixed $headers, $message = "Tente novamente"): string
    {
        return self::baseResponse($headers, "503", $message);
    }

    public static function e491RequestPending(mixed $headers, $message = "Request Pending"): string
    {
        return self::baseResponse($headers, "491", $message);
    }

    public static function modelMessage(array $headers, $message = "")
    {
        $uriFromBackup = \sip::extractUri($headers['From'][0]);
        $uriToBackup = \sip::extractUri($headers['To'][0]);
        $info = ['address' => $uriFromBackup['peer']['host'], 'port' => $uriFromBackup['peer']['port']];

        $model = [
            "method" => "MESSAGE",
            "methodForParser" => "MESSAGE sip:$uriFromBackup[user]@{$info['address']}:{$info['port']} SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP " . $info['address'] . ":" . $info['port'] . ";branch=z9hG4bK" . uniqid()],
                "Max-Forwards" => ["70"],
                "To" => [
                    sip::renderURI([
                        "user" => $uriFromBackup["user"],
                        "peer" => [
                            "host" => $info["address"],
                            "port" => $info["port"],
                        ],
                        "additional" => ["tag" => uniqid(time())]
                    ]),
                ],
                "From" => [
                    sip::renderURI([
                        "user" => $uriToBackup['user'],
                        "peer" => [
                            "host" => network::getLocalIp(),
                            "port" => cache::get('interface')['server']['port'],
                        ],
                        "additional" => [
                            "tag" => uniqid(time()),
                        ],
                    ]),
                ],
                "Call-ID" => $headers['Call-ID'],
                "CSeq" => intval($headers['CSeq'][0]) . " MESSAGE",
                "User-Agent" => [cache::global()['interface']['server']['serverName'] ?? "spechshop by lotus"],
                "Content-Type" => ["text/plain; charset=UTF-8"]
            ],
            "body" => $message,
        ];
        return $model;
    }

    public static function generateModelOptions(array $headers, $respondPort): array
    {
        if (!array_key_exists('Contact', $headers)) $headers['Contact'] = [sip::renderURI([
            'user' => 's',
            'peer' => [
                'host' => network::getLocalIp(),
                'port' => $respondPort
            ]
        ])];

        $uriContact = \sip::extractUri($headers['Contact'][0]);
        $uriContact['peer']['host'] = network::getLocalIp();
        $uriContact['peer']['port'] = $respondPort;
        $Ce = str_replace(['<', '>'], '', $headers['Contact'][0]);

        return [
            "method" => "OPTIONS",
            "methodForParser" => "OPTIONS " . $Ce . " SIP/2.0",
            "headers" => [
                "Via" => ["SIP/2.0/UDP " . network::getLocalIp() . ":" . $respondPort . ";branch=z9hG4bK-" . md5(random_bytes(4)) . ";rport"],
                "From" => ['"spechshop" ' . sip::renderURI([
                        'user' => 'spechshop',
                        'peer' => [
                            'host' => network::getLocalIp(),
                            'port' => $respondPort
                        ],
                        'additional' => ['tag' => uniqid()]
                    ])],
                "To" => [sip::renderURI([
                    'user' => sip::extractURI($headers['From'][0])['user'],
                    'peer' => [
                        'host' => $uriContact['peer']['host'],
                        'port' => $uriContact['peer']['port']
                    ]
                ])],
                "Max-Forwards" => ["70"],
                "Call-ID" => [$headers['Call-ID'][0] . '@' . network::getLocalIp()],
                "CSeq" => ["102 OPTIONS"],
                "User-Agent" => [cache::global()['interface']['server']['serverName']],
                "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE"],
                "Content-Length" => ["0"],
                "Contact" => [
                    sip::renderURI([
                        'user' => 'spechshop',
                        'peer' => [
                            'host' => network::getLocalIp(),
                            'port' => $respondPort
                        ]
                    ])
                ],
            ]
        ];


        // OPTIONS sip:mexicano3@100.66.11.152:7660;ob SIP/2.0
        // Via: SIP/2.0/UDP 72.60.7.163:5060;branch=z9hG4bK7b0f96e2;rport
        // Max-Forwards: 70
        // From: "asterisk" <sip:asterisk@72.60.7.163>;tag=as29f9974c
        // To: <sip:mexicano3@100.66.11.152:7660;ob>
        // Contact: <sip:asterisk@72.60.7.163:5060>
        // Call-ID: 5522dfeb4e984e6054c4b75431bf30a2@72.60.7.163:5060
        // CSeq: 102 OPTIONS
        // User-Agent: Asterisk PBX 13.38.3
        // Date: Wed, 12 Nov 2025 18:55:20 GMT
        // Allow: INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, SUBSCRIBE, NOTIFY, INFO, PUBLISH, MESSAGE
        // Supported: replaces, timer
        // Content-Length: 0
        //
        //return [
        //    "method" => "OPTIONS",
        //    "methodForParser" => "OPTIONS sip:{$this->host} SIP/2.0",
        //    "headers" => [
        //        "Via" => ["SIP/2.0/UDP {$this->localIp}:{$this->socketPortListen};branch=z9hG4bK-" . bin2hex(secure_random_bytes(4)) . ';rport'],
        //        "From" => ["<sip:{$this->username}@{$this->host}>"],
        //        "To" => ["<sip:{$this->host}>"],
        //        "Max-Forwards" => ["70"],
        //        "Call-ID" => [$this->callId],
        //        "CSeq" => [$this->csq . " OPTIONS"],
        //        "User-Agent" => [cache::global()["interface"]["server"]["serverName"]],
        //        "Allow" => ["INVITE, ACK, CANCEL, OPTIONS, BYE, REFER, NOTIFY, MESSAGE"],
        //        "Content-Length" => ["0"],
        //    ]
        //];
    }
}
