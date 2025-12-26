<?php

namespace libspech\Sip;

use libspech\Cache\cache;
use libspech\Cli\cli;
use libspech\Network\network;

#[AllowDynamicProperties]
class sip
{
    public static string $data;




    public static function parse($dataString = false): ?array
    {
        if ($dataString) $data = $dataString;
        else {
            return [
                'method' => 'empty',
                'headers' => [],
            ];
        }
        $f = self::sdpData($data);
        $solution = [];
        if (!empty($f['Content-Type'][0])) {
            if ($f['Content-Type'][0] == 'application/sdp') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0])
                    if ($f['Content-Length'][0] > 0) {
                        $sdp = substr($data, -intval($f['Content-Length'][0]));
                        $sdp = explode(PHP_EOL, $sdp);
                        $keys = [];
                        foreach ($sdp as $v) {
                            $k = explode("=", $v, 2);
                            if (strlen($k[0]) < 1) continue;
                            $keys[$k[0]][] = trim($k[1]);
                        }
                        $solution['sdp'] = $keys;
                    }
            } elseif ($f['Content-Type'][0] == 'message/sipfrag') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0]) {
                    $body = substr($data, -intval($f['Content-Length'][0]));
                    $solution['body'] = $body;
                }
            } elseif ($f['Content-Type'][0] == 'text/plain') {
                if (array_key_exists('Content-Length', $f) and $f['Content-Length'][0]) {
                    $body = substr($data, -intval($f['Content-Length'][0]));
                    $solution['body'] = $body;
                }
            }
        }

        $lk = null;
        $solution['headers'] = [];
        foreach ($f as $key => $value) {


            $lk = $key;
            if ($key == "") break;
            $solution['headers'][$key] = $value;
        }
        // pegar primeira  key de headers
        $firstKey = array_key_first($solution['headers']);
        $solution['methodForParser'] = trim(explode("\r\n", $data)[0]);
        if ($firstKey == $solution['methodForParser']) unset($solution['headers'][$firstKey]);
        $solution['method'] = key($f);
        $solution['methodForParser'] = trim(explode("\r\n", $data)[0]);
        if (str_contains($solution['method'], '2.0')) {
            $s = explode(" ", $solution['method']);
            $solution['methodForParser'] = "SIP/2.0 " . $s[(count($s) > 1) ? 1 : 0] . ((count($s) > 1) ? ' ' : '') .
                explode("2.0 " . $s[(count($s) > 1) ? 1 : 0] . ((count($s) > 1) ? ' ' : ''), $solution['method'])[1];
            $solution['method'] = $s[(count($s) > 1) ? 1 : 0];
        }
        $firstKey = array_key_first($solution['headers']);
        if ($firstKey == $solution['method']) unset($solution['headers'][$firstKey]);


        return $solution;
    }

    protected static function sdpData(string $data): ?array
    {
        $sdp = [];
        foreach (explode(PHP_EOL, $data) as $line) {
            $line = explode(":", $line, 2);
            $key = trim($line[0]);
            $val = @trim($line[1]);
            if (str_contains($key, ' sip')) {
                $key = explode(' sip', $key)[0];
                $val = 'sip:' . $val;
            }
            $sdp[$key][] = $val;
        }
        return $sdp;
    }

    public static function normalizeArrayKey(string $nameKey, string $normalized, array $data): array
    {
        $newData = [];
        foreach ($data as $key => $value) {
            $nkm = strtolower($nameKey);
            $ktl = strtolower($key);
            if ($nkm == $ktl) {
                $newData[$normalized] = $value;
            } else {
                $newData[$key] = $value;
            }
        }
        return $newData;
    }

    public static function security(array $data, array $info): false|string
    {
        $headers = $data['headers'];
        $from = $headers['From'][0];
        $to = $headers['To'][0];
        $uriFrom = sip::extractURI($from);
        $method = $data['method'];
        $token = $headers['Authorization'][0];
        $user = sip::getTrunkByUserFromDatabase($uriFrom['user']);
        if (!$user) {
            $connections = cache::getConnections();
            if (!array_key_exists('User-Agent', $headers)) $headers['User-Agent'] = ['sip-client'];
            $connections[$uriFrom['user']] = [
                'address' => $info['address'],
                'port' => $info['port'],
                'userAgent' => $headers['User-Agent'][0],
                'timestamp' => time(),
                'expires' => '3600'
            ];
            cache::updateConnections($connections);
            return false;
        }

        $realResponse = sip::generateResponse($user['account']['u'], value($token, 'realm="', '"'), $user['account']['p'], value($token, 'nonce="', '"'), value($token, 'uri="', '"'), $method);
        $receivedResponse = value($token, 'response="', '"');
        if ($realResponse !== $receivedResponse) {
            $connections[$uriFrom['user']] = [
                'address' => $info['address'],
                'port' => $info['port'],
                'userAgent' => $headers['User-Agent'][0],
                'timestamp' => time(),
                'expires' => '0'
            ];
            cache::updateConnections($connections);
            return false;
        }
        $trunkData = [
            'username' => $user['trunk']['u'],
            'password' => $user['trunk']['p'],
        ];

        $connections = cache::getConnections();
        if (!array_key_exists('User-Agent', $headers)) $headers['User-Agent'] = ['sip-client'];


        $connections[$uriFrom['user']] = [
            'address' => $info['address'],
            'port' => $info['port'],
            'userAgent' => $headers['User-Agent'][0],
            'timestamp' => time(),
            'expires' => '3600'
        ];
        cache::updateConnections($connections);
        $realm = value($token, 'realm="', '"');
        $password = $trunkData['password'];
        $nonce = value($token, 'nonce="', '"');
        $uriAuth = value($token, 'uri="', '"');
        $username = $trunkData['username'];
        return sip::generateAuthorizationHeader($username, $realm, $password, $nonce, $uriAuth, $method);
    }

    public static function extractVia(string $line): array
    {
        $result = [];

        // Divide o início do Via (protocolo e endereço) do resto
        if (preg_match('/^SIP\/2\.0\/(?P<transport>\w+)\s+(?P<host>[^;]+)/i', $line, $match)) {
            $result['transport'] = strtoupper($match['transport']);
            $hostParts = explode(':', $match['host']);
            $result['address'] = $hostParts[0];
            $result['port'] = isset($hostParts[1]) ? (int)$hostParts[1] : null;
        }

        // Extrai os parâmetros restantes (branch, rport, received, etc.)
        if (preg_match_all('/;\s*([^=;]+)=([^;]+)/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as [$_, $key, $value]) {
                $result[trim($key)] = trim($value);
            }
        }

        return $result;
    }

    public static function extractURI($line): array
    {
        if (!str_contains($line, 'sip:')) return [];

        $hasBrackets = str_contains($line, '<') && str_contains($line, '>');

        if ($hasBrackets) {
            // Handle format: <sip:user@host:port>
            $user = value($line, 'sip:', '@');
            $peerFirst = value($line, $user . '@', '>');
            $peerParts = explode(';', $peerFirst, 2);
            $hostPort = explode(':', $peerParts[0], 2);
            $additionalParams = [];

            $remaining = substr($line, strpos($line, '>') + 1);
            parse_str(str_replace(';', '&', $remaining), $additionalParams);
        } else {
            // Handle format: sip:user@host:port
            $sipPart = substr($line, strpos($line, 'sip:'));
            // Split by space or other delimiters to isolate the SIP URI
            $sipUri = preg_split('/[\s,;]/', $sipPart)[0];

            if (str_contains($sipUri, '@')) {
                $user = value($sipUri, 'sip:', '@');
                $peerPart = substr($sipUri, strpos($sipUri, '@') + 1);
            } else {
                $user = '';
                $peerPart = substr($sipUri, 4); // Remove 'sip:'
            }

            // Parse additional parameters from the full line
            $additionalParams = [];
            if (str_contains($line, ';')) {
                $paramsPart = substr($line, strpos($sipUri, ';') !== false ? strpos($line, $sipUri) + strlen($sipUri) : strlen($line));
                if (str_starts_with($paramsPart, ';')) {
                    parse_str(str_replace(';', '&', substr($paramsPart, 1)), $additionalParams);
                }
            }

            $peerParts = explode(';', $peerPart, 2);
            $hostPort = explode(':', $peerParts[0], 2);
        }

        if (str_contains($user, ':')) {
            $user = str_replace('>', '', $user);
            $hostPort = explode(':', $user, 2);
            $user = '';
        }

        $filterHost = filter_var($hostPort[0], FILTER_VALIDATE_IP);
        if (!$filterHost) $hostPort[0] = '127.0.0.1';
        if (empty($hostPort[1])) $hostPort[1] = '5060';
        $filterPort = filter_var($hostPort[1], FILTER_VALIDATE_INT);
        if (!$filterPort) $hostPort[1] = '5060';

        return [
            'user' => $user,
            'peer' => [
                'host' => $hostPort[0],
                'port' => $hostPort[1] ?? '5060',
                'extra' => $peerParts[1] ?? ''
            ],
            'additional' => $additionalParams ?? []
        ];
    }


    public static function getTrunkByUserFromDatabase($user): ?array
    {
        $database = cache::get('database');
        $trunks = json_decode(file_get_contents('trunks.json'), true);

        if (!is_array($trunks)) {
            sleep(1);
            $trunks = cache::get('trunks');
            if (!is_array($trunks)) {
                return null;
            }
        }


        foreach ($database as $account) {
            if ($account['u'] == $user) {
                if (!array_key_exists($account['t'], $trunks)) return null;
                try {
                    return [
                        'trunk' => [
                            ... $trunks[$account['t']],
                            'id' => $account['t']
                        ],
                        'account' => $account
                    ];
                } catch (Exception $e) {
                    return null;
                }
            }
        }
        return null;
    }

    public static function generateResponse($username, $realm, $password, $nonce, $uri, $method): string
    {
        $ha1 = md5("{$username}:{$realm}:{$password}");
        $ha2 = md5("{$method}:{$uri}");
        return md5("{$ha1}:{$nonce}:{$ha2}");
    }

    public static function generateAuthorizationHeader($username, $realm, $password, $nonce, $uri, $method): string
    {
        $ha1 = md5("{$username}:{$realm}:{$password}");
        $ha2 = md5("{$method}:{$uri}");
        $response = md5("{$ha1}:{$nonce}:{$ha2}");
        return 'Digest username="' . $username . '", '
            . 'realm="' . $realm . '", '
            . 'nonce="' . $nonce . '", '
            . 'uri="' . $uri . '", '
            . 'response="' . $response . '", '
            . 'algorithm=MD5';
    }

    public static function liteSecurity(mixed $data): false|string
    {
        $headers = $data['headers'];
        $authorization = $headers['Authorization'][0] ?? null;
        $username = value($authorization, 'username="', '"');
        $realm = value($authorization, 'realm="', '"');
        $nonce = value($authorization, 'nonce="', '"');
        $uri = value($authorization, 'uri="', '"');
        $method = $data['method'];
        $password = sip::findUsername($username)['p'];
        $realResponse = sip::generateResponse(
            $username,
            $realm,
            $password,
            $nonce,
            $uri,
            $data['method']
        );
        $receivedResponse = value($authorization, 'response="', '"');
        if ($realResponse !== $receivedResponse) return false;
        return sip::generateAuthorizationHeader($username, $realm, $password, $nonce, $uri, $method);
    }

    public static function findUsername(string $user): ?array
    {
        global $database;
        foreach ($database as $account) {
            if ($account['u'] == $user) {
                return $account;
            }
        }
        return null;
    }

    public static function findUsernameByAddress(string $address): ?array
    {
        global $database;
        foreach ($database as $account) {
            if (!array_key_exists('phosts', $account)) continue;
            $phosts = explode(',', $account['phosts']);
            foreach ($phosts as $phost) {
                if ($phost == $address) {
                    return $account;
                }
            }
        }
        return null;
    }

    public static function teachVia($user, $info): string
    {
        return sprintf("SIP/2.0/UDP {$info['address']}:{$info['port']};branch=z9hG4bK%s;extension=%s", uniqid(), $user);
    }

    public static function getVia(array $headers): ?array
    {
        $line = '';
        foreach ($headers['Via'] as $value) {
            if (str_contains($value, 'extension=')) {
                $line = $value;
                break;
            }
        }
        if (empty($line)) return null;
        $v = value($line, 'SIP/2.0/UDP ', ';branch=z9hG4bK');
        $v2 = value($line, 'SIP/2.0/', ';branch=z9hG4bK');
        return [
            'user' => value($line, 'extension=', PHP_EOL),
            'host' => getHost($v),
            'port' => getPort($v2),
        ];
    }

    public static function csq(mixed $stringOrArray): string
    {
        $s = '';
        if (is_array($stringOrArray)) {
            if (array_key_exists('CSeq', $stringOrArray)) {
                if (is_string($stringOrArray['CSeq'])) {
                    $s = $stringOrArray['CSeq'];
                } elseif (is_array($stringOrArray['CSeq'])) {
                    if (!empty($stringOrArray['CSeq'][0])) {
                        $s = $stringOrArray['CSeq'][0];
                    }
                }
                if (!empty($stringOrArray[0])) $s = $stringOrArray[0];
            } else {
                if (!empty($stringOrArray['CSeq'][0])) {
                    $s = $stringOrArray['CSeq'][0];
                } elseif (!empty($stringOrArray[0])) $s = $stringOrArray[0];
            }
        } elseif (is_string($stringOrArray)) {
            $s = $stringOrArray;
        }
        return self::letters($s);
    }

    public static function letters(string $string): string
    {
        $s = '';
        for ($i = 0; $i < strlen($string); $i++) {
            if (!ctype_digit($string[$i]) and !ctype_space($string[$i]) and ctype_alpha($string[$i])) $s .= $string[$i];
        }
        return $s;
    }

    public static function expireEvent(callable $callback)
    {
        $table = cache::global()['tableConnections'];
        $currentTime = time();
        $writer = [];
        foreach ($table as $user => $data) {
            $writer[$user] = $data;
            if ($currentTime - $data['timestamp'] > $data['expires']) {
                $table->del($user);
            }
        }
        file_put_contents('connections.json', json_encode($writer, JSON_PRETTY_PRINT));
        return $callback();
    }

    public static function findUserByAddress(array $address): ?string
    {
        $connections = cache::getConnections();
        foreach ($connections as $user => $connection) {
            if (arrayToString([
                    $connection['address'],
                    $connection['port']
                ]) === arrayToString($address)) {
                return $user;
            }
        }
        return null;
    }

    public static function processRtpPacket(string $packet): void
    {
        $rtpHeader = substr($packet, 0, 12);
        $payloadType = (ord($rtpHeader[1]) & 0x7F);
        if ($payloadType === 101) {
            self::extractDtmfEvent(substr($packet, 12));
        }
    }

    public static function extractDtmfEvent(string $payload): array
    {
        $event = ord($payload[0]);
        $volume = ord($payload[1]);
        $duration = unpack('n', substr($payload, 2, 2))[1];
        $endOfEvent = substr($payload, 4);
        if ($volume > 0) {
            return [
                'event' => $event,
                'volume' => $volume,
                'duration' => $duration,
                'endOfEvent' => $endOfEvent,
            ];
        } else {
            return [
                'event' => null,
                'volume' => null,
                'duration' => null,
                'endOfEvent' => $endOfEvent,
            ];
        }
        // retornar todos os detalhes do evento DTMF


    }

    public static function loadBestCallerId(mixed $number): string
    {
        if (str_starts_with($number, '0')) $number = substr($number, 1);
        if (str_starts_with($number, '55')) $number = substr($number, 2);
        $ddd = substr($number, 0, 2);
        return '55' . $ddd . rand(1000, 9999) . rand(1000, 9999);
    }

    public static function generateResponseProxy(
        string  $username,
        string  $password,
        string  $realm,
        string  $nonce,
        string  $uri,
        string  $method,
        string  $qop = "auth",
        string  $nc = "00000001",
        ?string $cnonce = null
    ): string
    {
        if (!$cnonce) {
            $cnonce = bin2hex(random_bytes(8));  // Gera cnonce aleatório
        }

        // 1. Calcular o HA1
        $HA1 = md5("{$username}:{$realm}:{$password}");

        // 2. Calcular o HA2
        $HA2 = md5("{$method}:{$uri}");

        // 3. Calcular o response
        $response = md5("{$HA1}:{$nonce}:{$nc}:{$cnonce}:{$qop}:{$HA2}");

        // 4. Montar o cabeçalho Proxy-Authorization
        $authHeader = sprintf(
            'Digest username="%s", realm="%s", nonce="%s", uri="%s", response="%s", cnonce="%s", qop=%s, nc=%s, algorithm=MD5',
            $username,
            $realm,
            $nonce,
            $uri,
            $response,
            $cnonce,
            $qop,
            $nc
        );

        return $authHeader;
    }

    public static function renderSolution(array $solution): string
    {
        if (!array_key_exists('headers', $solution)) $solution['headers'] = [];
        $needHeaders = [
            'Via', 'From', 'To', 'Call-ID', 'CSeq'
        ];
        foreach ($needHeaders as $needHeader) {
            if (!array_key_exists($needHeader, $solution['headers'])) {
                $solution['headers'][$needHeader] = [''];
            }
        }
        $solution['headers']['From'][0] = self::renderURI(self::extractURI($solution['headers']['From'][0]));
        $solution['headers']['To'][0] = self::renderURI(self::extractURI($solution['headers']['To'][0]));
        if (!array_key_exists('headers', $solution)) $solution['headers'] = [];
        if (!array_key_exists('method', $solution)) $solution['method'] = '';
        if (!array_key_exists('methodForParser', $solution)) $solution['methodForParser'] = '';
        if (empty($solution['headers']['Date']))
            $solution['headers']['Date'] = [date('D, d M Y H:i:s T')];

        if (!array_key_exists('X-Originating-IP', $solution['headers'])) $solution['headers']['X-Originating-IP'] = [];
        $solution['headers']['X-Originating-IP'][] = network::getLocalIp();
        if (!array_key_exists('method', $solution)) {
            var_dump($solution);

        }
        $method = $solution['method'];
        if (!array_key_exists('methodForParser', $solution)) {
            $solution['methodForParser'] = "SIP/2.0 $method Proxy";
        }



        $render = trim($solution['methodForParser']) . "\r\n";
        foreach ($solution['headers'] as $key => $value) {
            if ($key == $method) continue;
            if (str_contains($key, $solution['methodForParser'])) continue;
            if ($key == 'Content-Length') continue;
            if ($key == 'Content-Type') continue;
            if (is_array($value)) foreach ($value as $vx) {
                if (empty($vx)) continue;
                $render .= "$key: $vx\r\n";
                //$render .= "$key: $vx\r\n";
            }
        }
        $sdpRendering = '';
        if (array_key_exists('sdp', $solution)) {
            foreach ($solution['sdp'] as $key => $value) {
                foreach ($value as $v) {
                    $sdpRendering .= "$key=$v\r\n";
                }
            }
            $length = strlen($sdpRendering);
            if ($length > 0) {
                $render .= "Content-Type: application/sdp\r\n";
                $render .= "Content-Length: $length\r\n";
                $render .= "\r\n";
                $render .= $sdpRendering;
            }
        } elseif (array_key_exists('body', $solution)) {
            $render .= "Content-Type: {$solution['headers']['Content-Type'][0]}\r\n";
            $render .= "Content-Length: " . strlen($solution['body']) . "\r\n";
            $render .= "\r\n";
            $render .= $solution['body'];
        }

        if (!str_contains($render, 'Content-Length')) {
            $render .= "Content-Length: 0\r\n\r\n";
        }
        return $render;
    }

    public static function extractDtmfDetails(string $packet): array
    {
        $rtpHeader = substr($packet, 0, 12);
        $payloadType = (ord($rtpHeader[1]) & 0x7F);
        if ($payloadType !== 101) {
            return [
                'event' => null,
                'volume' => null,
                'duration' => null,
            ];
        }
        $event = ord($packet[12]);
        $volume = ord($packet[13]);
        $duration = unpack('n', substr($packet, 14, 2))[1];
        return [
            'event' => $event,
            'volume' => $volume,
            'duration' => $duration,
        ];
    }

    public static function getTrunkById(mixed $param)
    {
        $tableTrunks = cache::global()['tableTrunks'];
        if (is_numeric($param)) {
            return $tableTrunks[$param] ?? null;
        }
        if (is_string($param)) {
            foreach ($tableTrunks as $trunk) {
                if ($trunk['id'] == $param) {
                    return $trunk;
                }
            }
        }
        return null;
    }

    public function resolveHandler(): ?bool
    {
        try {
            $method = strtolower($this->method());
        } catch (Exception $e) {
            print cli::color('red', "erro na linha {$e->getLine()}\n");
            return false;
        }
        if ($method == 'banned') return false;
        if (!array_key_exists('headers', $this->solved)) return false;


        // Tratamento centralizado do rport para NAT traversal
        $headers = $this->solved['headers'];
        if (isset($headers['Via']) && !empty($headers['Via'][0])) {
            $via = $headers['Via'][0];
            if (str_contains($via, 'rport')) {
                // Extrair informações do Via original
                if (preg_match('/SIP\/2\.0\/UDP\s+([^;]+)(.*)/i', $via, $matches)) {
                    $hostPort = $matches[1];
                    $params = $matches[2];

                    // Se rport está presente mas sem valor, adicionar a porta real
                    if (preg_match('/rport(?!=)/i', $params)) {
                        $params = preg_replace('/rport(?!=)/i', 'rport=' . $this->info['port'], $params);
                    }

                    // Se não tem received, adicionar o IP real de onde recebemos
                    if (!str_contains($params, 'received')) {
                        $params .= ';received=' . $this->info['address'];
                    }

                    // Reconstituir o cabeçalho Via
                    $headers['Via'][0] = 'SIP/2.0/UDP ' . $hostPort . $params;
                    $this->solved['headers']['Via'][0] = $headers['Via'][0];
                }
            }
        }


        if (method_exists('handlers\\' . $method, 'resolve')) {
            try {
                return call_user_func('handlers\\' . $method . '::resolve', $this->socket, $this->solved, $this->info);
            } catch
            (Exception $e) {
                cli::pcl("2. erro na linha {$e->getLine()}", 'red');
                cli::pcl("3. {$e->getMessage()}}");
                return false;
            }
        }
        return false;
    }

    public function method(): ?string
    {
        $ip = $this->info['address'] ?? '';
        if (!is_array(cache::global()['bannedIps'])) {
            cache::define('bannedIps', []);
        }
        if (array_key_exists($ip, cache::global()['bannedIps'])) {
            return 'banned';
        }
        if (!array_key_exists('headers', $this->solved)) {
            return 'banned';
        }

        $method = $this->solved['method'] ?? '';
        if (empty($method)) {
            return 'banned';
        }


        if (ctype_digit($method[0])) {
            return 'sip' . $method;
        }
        return $method;
    }

    public static function renderURI(array $uriData): string
    {
        $user = trim($uriData['user']) ?? 's';

        $peer = $uriData['peer'] ?? [];
        $additional = $uriData['additional'] ?? [];
        $host = trim($peer['host']) ?? network::getLocalIp();
        if (empty($host)) {
            $host = network::getLocalIp();
        }
        $port = $peer['port'] ?? '5060';
        $extra = $peer['extra'] ?? '';


        $uri = "<sip:$user@$host";
        if (!empty($port) and $port !== '5060') {
            $uri .= ":$port";
        }
        if (!empty($extra)) {
            $uri .= ";$extra";
        }
        $uri .= ">";
        if (!empty($additional)) {
            $additionalParams = [];
            foreach ($additional as $key => $value) {
                $additionalParams[] = "$key=$value";
            }
            $uri .= ";" . implode(';', $additionalParams);
        }
        if (str_contains($uri, 'sip:@')) {
            $uri = str_replace('sip:@', 'sip:s@', $uri);
        }
        if (str_contains($uri, '>@')) {
            return self::renderURI([
                'user' => 'invalid',
                'peer' => [
                    'host' => network::getLocalIp(),
                    'port' => '5060',
                ],
            ]);
        }
        $validate = self::extractURI($uri);
        $peer=$validate['peer'];
        if (empty($peer['host'])) {
            return self::renderURI([
                'user' => 'invalid',
                'peer' => [
                    'host' => network::getLocalIp(),
                    'port' => '5060',
                ],
            ]);
        }

        return $uri;
    }
}


if (!function_exists('value')) {
    function value($string, $start, $end): ?string
    {
        return @explode($end, @explode($start, $string)[1])[0];
    }
}

function generateWavHeaderUlaw(int $dataLength, int $sampleRate = 8000, int $channels = 1): string
{
    $byteRate = $sampleRate * $channels;
    $blockAlign = $channels;

    return pack('A4V', 'RIFF', 36 + $dataLength)
        . 'WAVE'
        . pack('A4VvvVVvv', 'fmt ', 16, 7, $channels, $sampleRate, $byteRate, $blockAlign, 8)
        . pack('A4V', 'data', $dataLength);
}


function getHost($address): string
{
    if (str_contains($address, '[')) {
        preg_match('/\[(.*?)]/', $address, $matches);
        return $matches[1];
    } else {
        return explode(':', $address)[0];
    }
}

function getPort($address): string
{
    if (str_contains($address, '[')) {
        preg_match('/^.*]:(\d+)$/', $address, $matches);
        return $matches[1];
    } else {
        return explode(':', $address)[1];
    }
}

function arrayToString($array): string
{
    $string = '';
    foreach ($array as $key => $value) {
        $string .= "$key: $value\r\n";
    }
    return $string;
}


function mixAudios(array $inputFiles, string $outputFile): bool
{
    try {
        if (empty($inputFiles)) {
            throw new InvalidArgumentException("A lista de arquivos está vazia.");
        }
        $inputs = '';
        foreach ($inputFiles as $file) {
            if (!file_exists($file)) {
                throw new InvalidArgumentException("O arquivo $file não existe.");
            }
            $inputs .= " -i " . escapeshellarg($file);
        }
        $numInputs = count($inputFiles);
        $command = "ffmpeg $inputs -filter_complex amix=inputs=$numInputs:duration=longest:dropout_transition=3 " . escapeshellarg($outputFile) . " 2>&1";
        exec($command, $output, $returnVar);
        if ($returnVar !== 0) {
            throw new RuntimeException("Erro ao executar FFmpeg: " . implode("\n", $output));
        }
        return true;
    } catch (Exception $e) {
        echo "Erro em mixAudios: " . $e->getMessage() . "\n";
        return false;
    }
}





function extractRTPPayload(string $packet): ?string
{
    if (strlen($packet) < 8) {
        return null;
    }

    return substr($packet, 8);
}

function waveHead3(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
    $bitsPerSample = 16;
    $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
    $blockAlign = $channels * ($bitsPerSample / 8);
    return pack('a4V', 'RIFF', 36 + $dataLength)
        . 'WAVE'
        . pack('a4VvvVVvv', 'fmt ', 16, 1, $channels,
            $sampleRate, (int)$byteRate, (int)$blockAlign, $bitsPerSample)
        . pack('a4V', 'data', $dataLength);
}

function waveHead(int $dataLength, int $sampleRate, int $channels, int $audioFormat): string
{
    $bitsPerSample = ($audioFormat === 1 ? 16 : 8);
    $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
    $blockAlign = $channels * ($bitsPerSample / 8);

    return pack('a4V', 'RIFF', 36 + $dataLength)
        . 'WAVE'
        . pack('a4VvvVVvv', 'fmt ', 16, $audioFormat, $channels,
            $sampleRate, (int)$byteRate, (int)$blockAlign, $bitsPerSample)
        . pack('a4V', 'data', $dataLength);
}

function generateWavHeader(int $dataSize, int $sampleRate, int $channels): string
{
    $byteRate = $sampleRate * $channels * 1;
    $blockAlign = $channels * 1;
    return
        "RIFF" .
        pack('V', 36 + $dataSize) .
        "WAVE" .
        "fmt " .
        pack('V', 16) .
        pack('v', 7) .
        pack('v', $channels) .
        pack('V', $sampleRate) .
        pack('V', $byteRate) .
        pack('v', $blockAlign) .
        pack('v', 8) .
        "data" .
        pack('V', $dataSize);
}

function linear2ulaw(int $pcm_val): int
{
    $BIAS = 0x84;
    $seg_end = [0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF, 0x1FFF, 0x3FFF, 0x7FFF];

    if ($pcm_val < 0) {
        $pcm_val = $BIAS - $pcm_val;
        $mask = 0x7F;
    } else {
        $pcm_val += $BIAS;
        $mask = 0xFF;
    }

    $seg = searchSegment($pcm_val, $seg_end);
    if ($seg >= 8) {
        return (0x7F ^ $mask);
    }

    $uval = ($seg << 4) | (($pcm_val >> ($seg + 3)) & 0x0F);
    return $uval ^ $mask;
}

function linear2alaw(int $pcm_val): int
{
    $SEG_SHIFT = 4;
    $QUANT_MASK = 0xF;
    $seg_end = [0x1F, 0x3F, 0x7F, 0xFF, 0x1FF, 0x3FF, 0x7FF, 0xFFF];

    $pcm_val >>= 3;
    $mask = 0xD5;
    if ($pcm_val < 0) {
        $pcm_val = -$pcm_val - 1;
        $mask = 0x55;
    }

    $seg = searchSegment($pcm_val, $seg_end);
    if ($seg >= 8) return 0x7F ^ $mask;

    $aval = ($seg << $SEG_SHIFT);
    if ($seg < 2) {
        $aval |= ($pcm_val >> 1) & $QUANT_MASK;
    } else {
        $aval |= ($pcm_val >> $seg) & $QUANT_MASK;
    }

    return $aval ^ $mask;
}

function searchSegment(int $val, array $table): int
{
    foreach ($table as $i => $v) {
        if ($val <= $v) return $i;
    }
    return count($table) - 1;
}


function ulaw2linear(int $u_val): int
{
    $BIAS = 0x84;
    $SIGN_BIT = 0x80;
    $QUANT_MASK = 0xf;
    $SEG_SHIFT = 4;
    $SEG_MASK = 0x70;

    $u_val = ~$u_val & 0xFF;
    $t = (($u_val & $QUANT_MASK) << 3) + $BIAS;
    $t <<= (($u_val & $SEG_MASK) >> $SEG_SHIFT);

    return ($u_val & $SIGN_BIT) ? ($BIAS - $t) : ($t - $BIAS);
}

function alaw2linear(int $a_val): int
{
    $SIGN_BIT = 0x80;
    $QUANT_MASK = 0xf;
    $SEG_SHIFT = 4;
    $SEG_MASK = 0x70;

    $a_val ^= 0x55;
    $t = ($a_val & $QUANT_MASK) << 4;
    $seg = ($a_val & $SEG_MASK) >> $SEG_SHIFT;

    switch ($seg) {
        case 0:
            $t += 8;
            break;
        case 1:
            $t += 0x108;
            break;
        default:
            $t += 0x108;
            $t <<= ($seg - 1);
    }

    return ($a_val & $SIGN_BIT) ? -$t : $t; // <-- Aqui invertido
}


function alaw2ulaw(int $aval): int
{
    static $a2u = [
        1, 3, 5, 7, 9, 11, 13, 15,
        16, 17, 18, 19, 20, 21, 22, 23,
        24, 25, 26, 27, 28, 29, 30, 31,
        32, 32, 33, 33, 34, 34, 35, 35,
        36, 37, 38, 39, 40, 41, 42, 43,
        44, 45, 46, 47, 48, 48, 49, 49,
        50, 51, 52, 53, 54, 55, 56, 57,
        58, 59, 60, 61, 62, 63, 64, 64,
        65, 66, 67, 68, 69, 70, 71, 72,
        73, 74, 75, 76, 77, 78, 79, 80,
        80, 81, 82, 83, 84, 85, 86, 87,
        88, 89, 90, 91, 92, 93, 94, 95,
        96, 97, 98, 99, 100, 101, 102, 103,
        104, 105, 106, 107, 108, 109, 110, 111,
        112, 113, 114, 115, 116, 117, 118, 119,
        120, 121, 122, 123, 124, 125,
    ];
    $aval &= 0xFF;
    return ($aval & 0x80)
        ? (0xFF ^ $a2u[$aval ^ 0xD5])
        : (0x7F ^ $a2u[$aval ^ 0x55]);
}

function ulaw2alaw(int $uval): int
{
    static $u2a = [
        1, 1, 2, 2, 3, 3, 4, 4,
        5, 5, 6, 6, 7, 7, 8, 8,
        9, 10, 11, 12, 13, 14, 15, 16,
        17, 18, 19, 20, 21, 22, 23, 24,
        25, 27, 29, 31, 33, 34, 35, 36,
        37, 38, 39, 40, 41, 42, 43, 44,
        46, 48, 49, 50, 51, 52, 53, 54,
        55, 56, 57, 58, 59, 60, 61, 62,
        64, 65, 66, 67, 68, 69, 70, 71,
        72, 73, 74, 75, 76, 77, 78, 79,
        80, 82, 83, 84, 85, 86, 87, 88,
        89, 90, 91, 92, 93, 94, 95, 96,
        97, 98, 99, 100, 101, 102, 103, 104,
        105, 106, 107, 108, 109, 110, 111, 112,
        113, 114, 115, 116, 117, 118, 119, 120,
        121, 122, 123, 124, 125, 126, 127, 128];
    $uval &= 0xFF;
    return ($uval & 0x80)
        ? (0xD5 ^ ($u2a[0xFF ^ $uval] - 1))
        : (0x55 ^ ($u2a[0x7F ^ $uval] - 1));
}

function pcm2ulaw_(string $pcm): string
{
    $out = '';
    for ($i = 0; $i < strlen($pcm); $i += 2) {
        $sample = unpack('s', substr($pcm, $i, 2))[1];
        $out .= chr(linear2ulaw($sample));
    }
    return $out;
}


function pcmToUlaw(string $pcm): string
{
    $ulaw = '';
    for ($i = 0; $i < strlen($pcm); $i += 2) {
        $sample = unpack('s', substr($pcm, $i, 2))[1];
        $ulaw .= chr(linear2ulaw($sample));
    }
    return $ulaw;
}

function ulawToPcm(string $ulaw): string
{
    $pcm = '';
    foreach (str_split($ulaw) as $b) {
        $pcm .= pack('s', ulaw2linear(ord($b)));
    }
    return $pcm;
}

function pcmToAlaw(string $pcm): string
{
    $alaw = '';
    for ($i = 0; $i < strlen($pcm); $i += 2) {
        $sample = unpack('s', substr($pcm, $i, 2))[1];
        $alaw .= chr(linear2alaw($sample));
    }
    return $alaw;
}

function alawToPcm(string $alaw): string
{
    $pcm = '';
    foreach (str_split($alaw) as $b) {
        $pcm .= pack('s', alaw2linear(ord($b)));
    }
    return $pcm;
}

function alawToUlaw(string $alaw): string
{
    $ulaw = '';
    foreach (str_split($alaw) as $b) {
        $ulaw .= chr(alaw2ulaw(ord($b)));
    }
    return $ulaw;
}

function ulawToAlaw(string $ulaw): string
{
    $alaw = '';
    foreach (str_split($ulaw) as $b) {
        $alaw .= chr(ulaw2alaw(ord($b)));
    }
    return $alaw;
}


function volumeAverage(string $pcm): float
{
    $minLength = 160;
    if (empty($pcm)) {
        return 0.0;
    }
    if (strlen($pcm) < $minLength) {
        return 0.1;
    }
    $pcm = strlen($pcm) > $minLength ? substr($pcm, 0, $minLength) : $pcm;
    $soma = 0;
    $numSamples = 80;
    $maxValue = 32768.0;
    for ($i = 0; $i < $minLength; $i += 2) {
        $sample = unpack('s', substr($pcm, $i, 2))[1];
        $soma += $sample * $sample;
    }
    $rms = sqrt($soma / $numSamples);
    $normalized = $rms / $maxValue;
    return max(1, min(100, round($normalized * 100, 2)));
}

function traduzDTMF(int $evento): string
{
    $mapa = [
        0 => '0',
        1 => '1',
        2 => '2',
        3 => '3',
        4 => '4',
        5 => '5',
        6 => '6',
        7 => '7',
        8 => '8',
        9 => '9',
        10 => '*',
        11 => '#',
    ];
    return $mapa[$evento] ?? '';
}

function gerarDTMF_PCM(string $digito, float $duracao = 0.2, int $sampleRate = 8000): string
{
    $digito = traduzDTMF((int)$digito);
    $frequencias = [
        '1' => [
            697,
            1209,
        ],
        '2' => [
            697,
            1336,
        ],
        '3' => [
            697,
            1477,
        ],
        '4' => [
            770,
            1209,
        ],
        '5' => [
            770,
            1336,
        ],
        '6' => [
            770,
            1477,
        ],
        '7' => [
            852,
            1209,
        ],
        '8' => [
            852,
            1336,
        ],
        '9' => [
            852,
            1477,
        ],
        '*' => [
            941,
            1209,
        ],
        '0' => [
            941,
            1336,
        ],
        '#' => [
            941,
            1477,
        ],
    ];
    if (!isset($frequencias[$digito])) {
        throw new InvalidArgumentException("Dígito DTMF inválido.");
    }
    [$freqBaixa, $freqAlta] = $frequencias[$digito];
    $numSamples = (int)($sampleRate * $duracao);
    $pcmData = '';
    for ($i = 0; $i < $numSamples; $i++) {
        $t = $i / $sampleRate;
        $sample = 0.5 * (sin(2 * M_PI * $freqBaixa * $t) + sin(2 * M_PI * $freqAlta * $t));
        $pcmSample = (int)($sample * 32767);
        $pcmData .= pack('v', $pcmSample);
    }
    return $pcmData;
}

/**
 * Decodifica dados L16 Big Endian para PCM Little Endian
 *
 * @param string $l16Data Dados L16 em formato big endian
 * @return string Dados PCM em formato little endian
 */
function l16BigEndianToPcm(string $l16Data): string
{
    if (empty($l16Data)) {
        return '';
    }
    $dataLength = strlen($l16Data);
    if ($dataLength % 2 !== 0) {
        $l16Data = substr($l16Data, 0, $dataLength - 1);
        $dataLength--;
    }
    $pcmData = '';
    for ($i = 0; $i < $dataLength; $i += 2) {
        $bigEndianSample = unpack('n', substr($l16Data, $i, 2))[1];
        if ($bigEndianSample > 32767) {
            $signedSample = $bigEndianSample - 65536;
        } else {
            $signedSample = $bigEndianSample;
        }
        $pcmData .= pack('s', $signedSample);
    }
    return $pcmData;
}

function resampleDemoForFastDebug($pcm, $src, $dst, $ptime, $channels = 1): string
{
    $samplesIn = $src * $ptime / 1000;
    $samplesOut = $dst * $ptime / 1000;
    $chunk = substr($pcm, 0, $samplesIn * $channels * 2);
    if (strlen($chunk) < $samplesIn * $channels * 2) {
        $chunk = str_pad($chunk, $samplesIn * $channels * 2, "\x00");
    }
    $pcmIn = unpack("s*", $chunk);
    $pcmOut = [];
    foreach ($pcmIn as $sample) {
        for ($i = 0; $i < $samplesOut / $samplesIn; $i++) {
            $pcmOut[] = $sample;
        }
    }
    return pack("s*", ...$pcmOut);
}