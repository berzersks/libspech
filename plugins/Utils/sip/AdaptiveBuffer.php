<?php



use Plugin\Utils\cli;
use Swoole\Coroutine\Channel;

class AdaptiveBuffer
{
    private Channel $buffer;
    private int $bufferCapacity;
    private int $targetBufferMs;
    private int $maxBufferMs;
    private int $minBufferMs;
    private float $adaptationFactor;
    private array $metrics;
    private bool $enabled;
    private string $callId;

    public function __construct(string $callId, int $initialBufferMs = 50, int $maxBufferMs = 200)
    {
        $this->callId = $callId;
        $this->targetBufferMs = $initialBufferMs;
        $this->maxBufferMs = $maxBufferMs;
        $this->minBufferMs = 20;
        $this->adaptationFactor = 0.1;
        $this->enabled = false;

        // Buffer com capacidade baseada em pacotes por segundo
        $this->bufferCapacity = (int)ceil($maxBufferMs / 20 * 2); // 20ms por pacote, x2 para segurança
        $this->buffer = new Channel($this->bufferCapacity);

        $this->metrics = [
            'packets_buffered' => 0,
            'packets_dropped' => 0,
            'underruns' => 0,
            'overruns' => 0,
            'adaptive_changes' => 0,
            'current_buffer_size' => 0,
            'avg_buffer_utilization' => 0.0
        ];

        cli::pcl("🔧 [{$callId}] Buffer adaptativo inicializado: {$initialBufferMs}ms (max: {$maxBufferMs}ms)", 'blue');
    }

    /**
     * Habilita o buffer adaptativo
     */
    public function enable(): void
    {
        $this->enabled = true;
        cli::pcl("✅ [{$this->callId}] Buffer adaptativo HABILITADO", 'green');
    }

    /**
     * Desabilita o buffer adaptativo
     */
    public function disable(): void
    {
        $this->enabled = false;
        cli::pcl("❌ [{$this->callId}] Buffer adaptativo DESABILITADO", 'yellow');
    }

    /**
     * Adiciona um pacote ao buffer
     */
    public function addPacket(array $packetData, array $qualityAnalysis = []): bool
    {
        if (!$this->enabled) {
            return true; // Passar direto se desabilitado
        }

        $packetWithMetadata = [
            'data' => $packetData,
            'timestamp' => microtime(true),
            'sequence' => $packetData['sequence'] ?? 0,
            'quality' => $qualityAnalysis
        ];

        $this->metrics['current_buffer_size'] = $this->buffer->length();

        // Verificar se buffer está cheio
        if ($this->buffer->length() >= $this->bufferCapacity) {
            $this->metrics['overruns']++;

            // Tentar remover pacote mais antigo se possível
            if ($this->buffer->length() > 0) {
                $this->buffer->pop(0.001); // Timeout muito baixo
                cli::pcl("⚠️  [{$this->callId}] Buffer overflow - removendo pacote antigo", 'yellow');
            } else {
                $this->metrics['packets_dropped']++;
                cli::pcl("🚫 [{$this->callId}] Pacote descartado - buffer cheio", 'red');
                return false;
            }
        }

        $success = $this->buffer->push($packetWithMetadata, 0.001);
        if ($success) {
            $this->metrics['packets_buffered']++;
        } else {
            $this->metrics['packets_dropped']++;
        }

        // Adaptar tamanho do buffer baseado na qualidade
        if (!empty($qualityAnalysis)) {
            $this->adaptBufferSize($qualityAnalysis);
        }

        return $success;
    }

    /**
     * Retira um pacote do buffer quando apropriado
     */
    public function getPacket(float $timeout = 0.02): ?array
    {
        if (!$this->enabled) {
            return null;
        }

        $currentBufferSize = $this->buffer->length();
        $targetPacketCount = (int)ceil($this->targetBufferMs / 20); // 20ms por pacote

        // Só entregar se temos pacotes suficientes ou se timeout
        if ($currentBufferSize < $targetPacketCount) {
            // Buffer underrun - pode precisar adaptar
            if ($currentBufferSize === 0) {
                $this->metrics['underruns']++;
                cli::pcl("⏰ [{$this->callId}] Buffer underrun detectado", 'red');
            }

            // Esperar um pouco mais por pacotes
            $packet = $this->buffer->pop($timeout * 2);
        } else {
            // Buffer tem pacotes suficientes
            $packet = $this->buffer->pop(0.001); // Timeout baixo
        }

        // Atualizar métricas de utilização
        $utilization = $this->bufferCapacity > 0 ? $currentBufferSize / $this->bufferCapacity : 0;
        $this->metrics['avg_buffer_utilization'] = $this->metrics['avg_buffer_utilization'] * 0.9 + $utilization * 0.1;

        return $packet;
    }

    /**
     * Adapta o tamanho do buffer baseado na análise de qualidade
     */
    private function adaptBufferSize(array $qualityAnalysis): void
    {
        if (!isset($qualityAnalysis['overall_quality'])) {
            return;
        }

        $quality = $qualityAnalysis['overall_quality'];
        $oldTarget = $this->targetBufferMs;

        // Lógica de adaptação
        if ($quality['needs_adaptation']) {
            foreach ($quality['issues'] as $issue) {
                if (strpos($issue, 'PACKET_LOSS') !== false) {
                    // Aumentar buffer para compensar perda de pacotes
                    $lossRate = (float)filter_var($issue, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $increase = min(30, $lossRate * 2);
                    $this->targetBufferMs += (int)($increase * $this->adaptationFactor);

                } elseif (strpos($issue, 'HIGH_JITTER') !== false) {
                    // Aumentar buffer para suavizar jitter
                    $jitterValue = (float)filter_var($issue, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                    $increase = min(40, $jitterValue);
                    $this->targetBufferMs += (int)($increase * $this->adaptationFactor);

                } elseif (strpos($issue, 'TIMING_ISSUES') !== false) {
                    // Aumentar buffer para compensar problemas de timing
                    $this->targetBufferMs += (int)(20 * $this->adaptationFactor);
                }
            }
        } else {
            // Qualidade boa - pode reduzir buffer gradualmente
            if ($this->targetBufferMs > $this->minBufferMs && $quality['score'] > 0.8) {
                $this->targetBufferMs -= (int)(5 * $this->adaptationFactor);
            }
        }

        // Aplicar limites
        $this->targetBufferMs = max($this->minBufferMs, min($this->maxBufferMs, $this->targetBufferMs));

        // Log mudanças significativas
        if (abs($oldTarget - $this->targetBufferMs) > 5) {
            $this->metrics['adaptive_changes']++;
            $direction = $this->targetBufferMs > $oldTarget ? 'AUMENTADO' : 'REDUZIDO';
            cli::pcl("🔄 [{$this->callId}] Buffer {$direction}: {$oldTarget}ms → {$this->targetBufferMs}ms (qualidade: {$quality['level']})", 'cyan');
        }
    }

    /**
     * Força adaptação baseada em recomendações externas
     */
    public function applyRecommendation(array $recommendation): void
    {
        switch ($recommendation['type']) {
            case 'BUFFER_INCREASE':
                $newTarget = (int)$recommendation['suggested_buffer_ms'];
                if ($newTarget > $this->targetBufferMs) {
                    $this->targetBufferMs = min($this->maxBufferMs, $newTarget);
                    cli::pcl("📈 [{$this->callId}] Buffer forçado para {$this->targetBufferMs}ms por recomendação", 'yellow');
                }
                break;

            case 'ADAPTIVE_BUFFER':
                $this->enable();
                $newTarget = (int)$recommendation['suggested_buffer_ms'];
                $this->targetBufferMs = min($this->maxBufferMs, $newTarget);
                cli::pcl("🎯 [{$this->callId}] Buffer adaptativo ajustado para {$this->targetBufferMs}ms", 'green');
                break;
        }
    }

    /**
     * Obtém estatísticas do buffer
     */
    public function getStats(): array
    {
        $currentLength = $this->buffer->length();

        return array_merge($this->metrics, [
            'enabled' => $this->enabled,
            'target_buffer_ms' => $this->targetBufferMs,
            'max_buffer_ms' => $this->maxBufferMs,
            'current_length' => $currentLength,
            'buffer_capacity' => $this->bufferCapacity,
            'current_utilization' => $this->bufferCapacity > 0 ? $currentLength / $this->bufferCapacity : 0,
        ]);
    }

    /**
     * Reset do buffer e métricas
     */
    public function reset(): void
    {
        while ($this->buffer->length() > 0) {
            $this->buffer->pop(0.001);
        }

        $this->metrics = [
            'packets_buffered' => 0,
            'packets_dropped' => 0,
            'underruns' => 0,
            'overruns' => 0,
            'adaptive_changes' => 0,
            'current_buffer_size' => 0,
            'avg_buffer_utilization' => 0.0
        ];

        cli::pcl("🔄 [{$this->callId}] Buffer resetado", 'blue');
    }

    /**
     * Fecha o buffer
     */
    public function close(): void
    {
        $this->buffer->close();
        $stats = $this->getStats();

        cli::pcl("📊 [{$this->callId}] Buffer fechado - Estatísticas:", 'blue');
        cli::pcl("   - Pacotes buffered: {$stats['packets_buffered']}", 'blue');
        cli::pcl("   - Pacotes dropped: {$stats['packets_dropped']}", 'blue');
        cli::pcl("   - Underruns: {$stats['underruns']}", 'blue');
        cli::pcl("   - Overruns: {$stats['overruns']}", 'blue');
        cli::pcl("   - Adaptações: {$stats['adaptive_changes']}", 'blue');
    }
}
