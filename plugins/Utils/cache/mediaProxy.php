<?php

namespace plugins\Utils;

use Swoole\Coroutine\Socket;

class mediaProxy
{
    const CODECS = [
        'G729' => 18,
        'PCMU' => 0,
    ];

    private Socket $socket;
    private array $buffers = [];    // [user => ['codec' => ..., 'data' => [...]]]
    private array $rtpState = [];   // [user => ['seq' => ..., 'ts' => ..., 'ssrc' => ...]]
    private array $peerToUser = []; // [ip:port => user]

    public function __construct(array $peerUserMap = [], int $listenPort = 6000)
    {
        $this->peerToUser = $peerUserMap;
        go(fn() => $this->run($listenPort));
    }

    private function run(int $port)
    {
        $this->socket = new Socket(AF_INET, SOCK_DGRAM, 0);

        if (!$this->socket->bind('', $port)) {
            throw new \RuntimeException("Erro ao abrir socket na porta $port");
        }

        while (true) {
            $packet = $this->socket->recvfrom($peer, 0.2);
            if (!$packet || strlen($packet) < 12) continue;

            $peerKey = "{$peer['address']}:{$peer['port']}";
            $user = $this->peerToUser[$peerKey] ?? null;
            if (!$user) continue; // ignorar peer não registrado

            $rtpHeader = substr($packet, 0, 12);
            $payload = substr($packet, 12);
            $pt = ord($rtpHeader[1]) & 0x7F;

            // Inicializa codec se necessário
            $this->buffers[$user]['codec'] ??= array_search($pt, self::CODECS);

            // Atualiza RTP state
            $unpacked = unpack('Cbyte1/Cbyte2/nseq/Nts/Nssrc', $rtpHeader);
            $this->rtpState[$user] = [
                'seq' => $unpacked['seq'],
                'ts' => $unpacked['ts'],
                'ssrc' => $unpacked['ssrc'],
            ];

            // Processa pacote
            if (self::CODECS[$this->buffers[$user]['codec']] === 18 && strlen($payload) % 10 === 0) {
                $frames = \bcg729DecodeStream($payload); // G.729 → PCM
                foreach ($frames as $f) {
                    $this->buffers[$user]['data'][] = $f['output'];
                }
            } elseif (self::CODECS[$this->buffers[$user]['codec']] === 0) {
                $this->buffers[$user]['data'][] = $payload; // µ-law direto
            }
        }
    }

    private function buildRtpHeader(string $user, int $payloadType, int $samples): string
    {
        $state = &$this->rtpState[$user];
        $seq = ($state['seq'] = ($state['seq'] + 1) % 65536);
        $ts = ($state['ts'] = ($state['ts'] + $samples));
        $ssrc = $state['ssrc'];

        $version = 2 << 6;
        $payloadByte = $payloadType & 0x7F;

        return pack('CCnNN', $version, $payloadByte, $seq, $ts, $ssrc);
    }

    public function getFrame(int $codec, string $user): ?string
    {
        if (!isset($this->buffers[$user]['data'][0])) return null;

        $frame = array_shift($this->buffers[$user]['data']);

        if ($codec === 18) {
            $res = bcg729EncodeStream($frame); // PCM → G.729
            $payload = $res[0]['output'] ?? null;
            if (!$payload) return null;
            $header = $this->buildRtpHeader($user, 18, 0); // G.729 = timestamp fixo
            return $header . $payload;
        }

        if ($codec === 0) {
            $payload = bcg729PcmToUlaw($frame); // PCM → µ-law
            $header = $this->buildRtpHeader($user, 0, 160); // PCMU = 20ms = 160 samples
            return $header . $payload;
        }

        return null;
    }

    public function getPcmFrame(string $user): ?string
    {
        return $this->buffers[$user]['data'][0] ?? null;
    }

    public function getUsers(): array
    {
        return array_keys($this->buffers);
    }

    public function getCodec(string $user): ?string
    {
        return $this->buffers[$user]['codec'] ?? null;
    }

    public function transcode(string $user, int $targetCodec): ?array
    {
        if (!isset($this->buffers[$user]['data'][0])) {
            return null;
        }

        $packet = $this->getFrame($targetCodec, $user);
        if (!$packet) return null;

        // Procura peer IP:PORT que corresponde a esse usuário
        $peer = array_search($user, $this->peerToUser);
        if (!$peer) return null;

        [$ip, $port] = explode(':', $peer);

        return [
            'packet' => $packet,
            'codec' => $targetCodec,
            'peer' => ['address' => $ip, 'port' => (int)$port]
        ];
    }


    public function registerUser(string $user, string $peer, string $codec)
    {
        $this->peerToUser[$peer] = $user;
        $this->buffers[$user]['codec'] = $codec;
        $this->buffers[$user]['data'] = [];
        $this->rtpState[$user] = [
            'seq' => rand(0, 65535),
            'ts' => rand(0, 999999),
            'ssrc' => rand(1, 0xFFFFFFFF),
        ];
    }
}
