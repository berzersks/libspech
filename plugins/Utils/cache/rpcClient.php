<?php
use Plugin\Utils\cli;
class rpcClient
{
    protected string $host;
    protected int $port;
    protected \Swoole\Coroutine\Socket $socket;
    protected array $pendingRequests = [];
    protected bool $isRunning = false;
    public function __construct(string $host = '127.0.0.1', int $port = 9503) {
        $this->host = $host;
        $this->port = $port;
        $this->socket = new \Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, SOL_UDP);
        // Inicia o loop de recepção de respostas
        $this->startReceiveLoop();
    }
    /**
     * Inicia corrotina para receber respostas UDP
     */
    protected function startReceiveLoop(): void
    {
        if ($this->isRunning) {
            return;
        }
        $this->isRunning = true;
        \Swoole\Coroutine::create(function () {
            $lastActivity = microtime(true);
            $maxIdleTime = 30; // 30 segundos sem atividade

            while ($this->isRunning) {
                $data = $this->socket->recvfrom($info, 0.1);
                if ($data !== false && !empty($data)) {
                    $this->handleResponse($data);
                    $lastActivity = microtime(true);
                }

                // Limpa requisições antigas periodicamente
                if (count($this->pendingRequests) > 0) {
                    $this->cleanupPendingRequests();
                    $lastActivity = microtime(true);
                }

                // Para automaticamente se não houver atividade por muito tempo
                if (microtime(true) - $lastActivity > $maxIdleTime && empty($this->pendingRequests)) {
                    cli::pcl("RPC cliente inativo por {$maxIdleTime}s, encerrando...", 'yellow');
                    break;
                }
            }

            // Cleanup final
            $this->isRunning = false;
            if (isset($this->socket)) {
                $this->socket->close();
            }
            cli::pcl("RPC cliente foi fechado com sucesso!", 'bold_green');
        });
    }
    /**
     * Processa resposta recebida e resolve a requisição pendente
     */
    protected function handleResponse(string $data): void
    {
        $response = json_decode($data, true);
        if (!$response || !isset($response['token'])) {
            return;
        }
        $token = $response['token'];
        if (isset($this->pendingRequests[$token])) {
            $this->pendingRequests[$token]['response'] = $response['data'] ?? null;
            $this->pendingRequests[$token]['received'] = true;
        }
    }
    /**
     * Gera token único para requisição
     */
    protected function generateToken(): string
    {
        return uniqid(getmypid() . '_', true);
    }
    public function rpcSet(string $cid, array $data): ?string
    {
        return $this->sendRaw([
            'action' => 'set',
            'cid' => $cid,
            'data' => $data,
        ]);
    }
    protected function sendRaw(array $request, int $timeout = 5, int $maxRetries = 3): ?string
    {
        $token = $this->generateToken();
        $request['token'] = $token;
        // Registra requisição pendente
        $this->pendingRequests[$token] = [
            'request' => $request,
            'response' => null,
            'received' => false,
            'sent_at' => microtime(true),
        ];
        $json = json_encode($request);
        $retries = 0;
        $startTime = microtime(true);
        while ($retries < $maxRetries && microtime(true) - $startTime < $timeout) {
            try {
                // Envia requisição UDP
                $sent = $this->socket->sendto($this->host, $this->port, $json);
                $sent = true;
                if (!$sent) {
                    error_log("RPC UDP Send failed for token: {$token}");
                    $retries++;
                    \Swoole\Coroutine::sleep(0.1 * $retries);
                    // Backoff exponencial
                    continue;
                }
                // Aguarda resposta
                $waitStart = microtime(true);
                while (microtime(true) - $waitStart < $timeout / $maxRetries) {
                    if ($this->pendingRequests[$token]['received']) {
                        $response = $this->pendingRequests[$token]['response'];
                        unset($this->pendingRequests[$token]);
                        return $response;
                    }
                    \Swoole\Coroutine::sleep(0.1);
                    // 10ms
                }
                $retries++;
                error_log("RPC UDP timeout for token: {$token} (retry {$retries}/{$maxRetries})");
            } catch (\Exception $e) {
                error_log("RPC UDP Error: " . $e->getMessage() . " for token: {$token}");
                $retries++;
                \Swoole\Coroutine::sleep(0.1 * $retries);
            }
        }
        // Limpa requisição pendente em caso de falha
        unset($this->pendingRequests[$token]);
        error_log("RPC UDP failed after {$maxRetries} retries for: " . substr($json, 0, 100));
        return null;
    }
    public function rpcGet(string $cid): ?string
    {
        return $this->sendRaw([
            'action' => 'get',
            'cid' => $cid,
        ]);
    }
    public function rpcDelete(string $cid): ?string
    {
        return $this->sendRaw([
            'action' => 'delete',
            'cid' => $cid,
        ]);
    }
    public function rpcGetNonRunning(): ?string
    {
        return $this->sendRaw(['action' => 'get_non_running']);
    }
    /**
     * Limpa requisições pendentes antigas (mais de 30 segundos)
     */
    public function cleanupPendingRequests(): void
    {
        $now = microtime(true);
        foreach ($this->pendingRequests as $token => $request) {
            if ($now - $request['sent_at'] > 30) {
                unset($this->pendingRequests[$token]);
            }
        }
    }

    /**
     * Para o loop de recepção e fecha o socket
     */
    public function stop(): void
    {
        $this->isRunning = false;
        if (isset($this->socket)) {
            $this->socket->close();
        }
    }

    /**
     * Método de fechamento manual
     */
    public function close(): void
    {
        $this->stop();
    }

    /**
     * Destructor para garantir que o socket seja fechado
     */
    public function __destruct()
    {
        $this->stop();
    }
}