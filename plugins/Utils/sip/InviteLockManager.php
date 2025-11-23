<?php

namespace sip;

use Plugin\Utils\cli;
use plugins\Utils\cache;

/**
 * Gerenciador de travas para prevenir processamento duplicado de INVITEs
 * e permitir reutilização de sockets
 */
class InviteLockManager
{
    private const LOCK_TIMEOUT = 60; // 60 segundos
    private const SOCKET_REUSE_KEY = 'inviteSocketReuse';
    private const PROCESSING_LOCK_KEY = 'inviteProcessingLock';

    /**
     * Inicializa o gerenciador de travas
     */
    public static function initialize(): void
    {
        if (!cache::get(self::PROCESSING_LOCK_KEY)) {
            cache::define(self::PROCESSING_LOCK_KEY, []);
        }

        if (!cache::get(self::SOCKET_REUSE_KEY)) {
            cache::define(self::SOCKET_REUSE_KEY, []);
        }

    }

    /**
     * Tenta criar uma trava para o callId especificado
     * 
     * @param string $callId ID da chamada
     * @param array $metadata Metadados adicionais para a trava
     * @return bool True se a trava foi criada com sucesso
     */
    public static function lockInvite(string $callId, array $metadata = []): bool
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];

        // Limpar travas órfãs antes de verificar
        self::cleanOrphanedLocks();

        // Verificar se já está travado
        if (array_key_exists($callId, $locks)) {
            $lockTime = $locks[$callId]['timestamp'];
            $lockPid = $locks[$callId]['pid'] ?? 0;

            cli::pcl("INVITE já está sendo processado para callId: $callId (PID: $lockPid, há " . (time() - $lockTime) . "s)", 'red');
            return false;
        }

        // Criar nova trava
        $lockData = [
            'timestamp' => time(),
            'pid' => getmypid(),
            'coroutine_id' => \Swoole\Coroutine::getCid(),
            'metadata' => $metadata
        ];

        $locks[$callId] = $lockData;
        cache::define(self::PROCESSING_LOCK_KEY, $locks);

        cli::pcl("Trava criada para callId: $callId (PID: " . getmypid() . ", CID: " . \Swoole\Coroutine::getCid() . ")", 'green');

        return true;
    }

    /**
     * Remove a trava do callId especificado
     * 
     * @param string $callId ID da chamada
     * @return bool True se a trava foi removida
     */
    public static function unlockInvite(string $callId): bool
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];

        if (array_key_exists($callId, $locks)) {
            unset($locks[$callId]);
            cache::define(self::PROCESSING_LOCK_KEY, $locks);
            cli::pcl("Trava removida para callId: $callId", 'blue');

            // Também remover socket reutilizável se existir
            self::removeReusableSocket($callId);


            return true;
        }

        return false;
    }

    /**
     * Verifica se um callId está travado
     * 
     * @param string $callId ID da chamada
     * @return bool True se está travado
     */
    public static function isLocked(string $callId): bool
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];
        return array_key_exists($callId, $locks);
    }

    /**
     * Obtém informações da trava
     * 
     * @param string $callId ID da chamada
     * @return array|null Informações da trava ou null se não existir
     */
    public static function getLockInfo(string $callId): ?array
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];
        return $locks[$callId] ?? null;
    }

    /**
     * Armazena um socket para reutilização
     * 
     * @param string $callId ID da chamada
     * @param mixed $socket Socket a ser armazenado
     * @param array $socketInfo Informações adicionais do socket
     */
    public static function storeReusableSocket(string $callId, $socket, array $socketInfo = []): void
    {
        $sockets = cache::get(self::SOCKET_REUSE_KEY) ?? [];

        $sockets[$callId] = [
            'socket' => $socket,
            'info' => $socketInfo,
            'timestamp' => time(),
            'pid' => getmypid()
        ];

        cache::define(self::SOCKET_REUSE_KEY, $sockets);
        cli::pcl("Socket armazenado para reutilização callId: $callId", 'yellow');
    }

    /**
     * Recupera um socket reutilizável
     * 
     * @param string $callId ID da chamada
     * @return array|null Array com socket e info ou null se não existir
     */
    public static function getReusableSocket(string $callId): ?array
    {
        $sockets = cache::get(self::SOCKET_REUSE_KEY) ?? [];

        if (array_key_exists($callId, $sockets)) {
            $socketData = $sockets[$callId];

            // Verificar se não é muito antigo (5 minutos)
            if (time() - $socketData['timestamp'] > 300) {
                self::removeReusableSocket($callId);
                cli::pcl("Socket expirado removido para callId: $callId", 'yellow');
                return null;
            }

            cli::pcl("Socket reutilizado para callId: $callId", 'cyan');
            return $socketData;
        }

        return null;
    }

    /**
     * Remove um socket reutilizável
     * 
     * @param string $callId ID da chamada
     */
    public static function removeReusableSocket(string $callId): void
    {
        $sockets = cache::get(self::SOCKET_REUSE_KEY) ?? [];

        if (array_key_exists($callId, $sockets)) {
            unset($sockets[$callId]);
            cache::define(self::SOCKET_REUSE_KEY, $sockets);
            cli::pcl("Socket removido do cache de reutilização callId: $callId", 'yellow');
        }
    }

    /**
     * Limpa travas órfãs (muito antigas ou de processos mortos)
     */
    public static function cleanOrphanedLocks(): void
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];
        $cleaned = 0;
        $currentTime = time();

        foreach ($locks as $callId => $lockData) {
            $lockAge = $currentTime - $lockData['timestamp'];
            $lockPid = $lockData['pid'] ?? 0;

            // Remover se muito antigo ou processo não existe mais
            if ($lockAge > self::LOCK_TIMEOUT || ($lockPid > 0 && !self::processExists($lockPid))) {
                unset($locks[$callId]);
                $cleaned++;
                cli::pcl("Trava órfã removida: callId=$callId, idade={$lockAge}s, pid=$lockPid", 'yellow');
            }
        }

        if ($cleaned > 0) {
            cache::define(self::PROCESSING_LOCK_KEY, $locks);
            cli::pcl("Limpeza concluída: $cleaned travas órfãs removidas", 'green');
        }
    }

    /**
     * Limpa sockets expirados
     */
    public static function cleanExpiredSockets(): void
    {
        $sockets = cache::get(self::SOCKET_REUSE_KEY) ?? [];
        $cleaned = 0;
        $currentTime = time();

        foreach ($sockets as $callId => $socketData) {
            $socketAge = $currentTime - $socketData['timestamp'];

            // Remover se muito antigo (5 minutos)
            if ($socketAge > 300) {
                unset($sockets[$callId]);
                $cleaned++;
                cli::pcl("Socket expirado removido: callId=$callId, idade={$socketAge}s", 'yellow');
            }
        }

        if ($cleaned > 0) {
            cache::define(self::SOCKET_REUSE_KEY, $sockets);
            cli::pcl("Limpeza de sockets concluída: $cleaned sockets removidos", 'green');
        }
    }

    /**
     * Obtém estatísticas do gerenciador
     * 
     * @return array Estatísticas atuais
     */
    public static function getStats(): array
    {
        $locks = cache::get(self::PROCESSING_LOCK_KEY) ?? [];
        $sockets = cache::get(self::SOCKET_REUSE_KEY) ?? [];

        return [
            'active_locks' => count($locks),
            'reusable_sockets' => count($sockets),
            'oldest_lock' => self::getOldestLockAge($locks),
            'oldest_socket' => self::getOldestSocketAge($sockets)
        ];
    }

    /**
     * Força limpeza de todas as travas e sockets
     * CUIDADO: Use apenas em situações de emergência
     */
    public static function forceCleanAll(): void
    {
        cache::define(self::PROCESSING_LOCK_KEY, []);
        cache::define(self::SOCKET_REUSE_KEY, []);
        cli::pcl("LIMPEZA FORÇADA: Todas as travas e sockets removidos", 'red');
    }

    /**
     * Verifica se um processo existe
     * 
     * @param int $pid ID do processo
     * @return bool True se o processo existe
     */
    private static function processExists(int $pid): bool
    {
        if ($pid <= 0) return false;

        // No Linux/Unix, usar kill -0 para verificar se processo existe
        return posix_kill($pid, 0);
    }

    /**
     * Obtém a idade da trava mais antiga
     * 
     * @param array $locks Array de travas
     * @return int Idade em segundos ou 0 se não houver travas
     */
    private static function getOldestLockAge(array $locks): int
    {
        if (empty($locks)) return 0;

        $oldestTime = min(array_column($locks, 'timestamp'));
        return time() - $oldestTime;
    }

    /**
     * Obtém a idade do socket mais antigo
     * 
     * @param array $sockets Array de sockets
     * @return int Idade em segundos ou 0 se não houver sockets
     */
    private static function getOldestSocketAge(array $sockets): int
    {
        if (empty($sockets)) return 0;

        $oldestTime = min(array_column($sockets, 'timestamp'));
        return time() - $oldestTime;
    }
}
