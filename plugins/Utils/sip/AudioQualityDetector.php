<?php

namespace sip;

use Plugin\Utils\cli;

class AudioQualityDetector
{
    private array $packetHistory = [];
    private array $ssrcMetrics = [];
    private float $jitterThreshold = 30.0; // ms
    private float $lossThreshold = 5.0; // %
    private int $maxHistorySize = 1000;
    private int $adaptationWindow = 50; // pacotes para análise

    public function __construct()
    {
        cli::pcl("🔍 AudioQualityDetector inicializado", 'blue');
    }

    /**
     * Analisa a qualidade de um pacote RTP recebido
     */
    public function analyzePacket(int $ssrc, int $sequence, int $timestamp, float $arrivalTime, string $payload): array
    {
        if (!isset($this->ssrcMetrics[$ssrc])) {
            $this->initializeSSRCMetrics($ssrc);
        }

        $metrics = &$this->ssrcMetrics[$ssrc];
        $metrics['total_packets']++;
        $metrics['last_arrival'] = $arrivalTime;

        // Detectar perda de pacotes
        $lossInfo = $this->detectPacketLoss($ssrc, $sequence);

        // Calcular jitter
        $jitterInfo = $this->calculateJitter($ssrc, $timestamp, $arrivalTime);

        // Analisar qualidade do áudio
        $audioQuality = $this->analyzeAudioQuality($payload);

        // Detectar problemas de sincronização
        $syncIssues = $this->detectSyncIssues($ssrc, $timestamp, $arrivalTime);

        $analysis = [
            'ssrc' => $ssrc,
            'sequence' => $sequence,
            'timestamp' => $timestamp,
            'arrival_time' => $arrivalTime,
            'packet_loss' => $lossInfo,
            'jitter' => $jitterInfo,
            'audio_quality' => $audioQuality,
            'sync_issues' => $syncIssues,
            'overall_quality' => $this->calculateOverallQuality($lossInfo, $jitterInfo, $audioQuality, $syncIssues)
        ];

        $this->updatePacketHistory($ssrc, $analysis);

        return $analysis;
    }

    /**
     * Inicializa métricas para um SSRC /usr/local/bin/php /home/lotus/PROJETOS/MAKITA/server.php
     */
    private function initializeSSRCMetrics(int $ssrc): void
    {
        $this->ssrcMetrics[$ssrc] = [
            'total_packets' => 0,
            'lost_packets' => 0,
            'last_sequence' => -1,
            'last_timestamp' => -1,
            'last_arrival' => 0,
            'jitter_samples' => [],
            'transit_variance' => 0,
            'mean_deviation' => 0,
            'quality_scores' => [],
        ];

        $this->packetHistory[$ssrc] = [];
    }

    /**
     * Detecta perda de pacotes baseado na sequência
     */
    private function detectPacketLoss(int $ssrc, int $sequence): array
    {
        $metrics = &$this->ssrcMetrics[$ssrc];
        $lossInfo = [
            'lost_count' => 0,
            'expected_sequence' => $sequence,
            'gap_detected' => false,
            'loss_percentage' => 0.0
        ];

        if ($metrics['last_sequence'] !== -1) {
            $expectedSeq = ($metrics['last_sequence'] + 1) & 0xFFFF; // 16-bit wrap

            if ($sequence !== $expectedSeq) {
                if ($sequence > $expectedSeq) {
                    // Pacotes perdidos
                    $lost = $sequence - $expectedSeq;
                    $metrics['lost_packets'] += $lost;
                    $lossInfo['lost_count'] = $lost;
                    $lossInfo['gap_detected'] = true;

                    cli::pcl("📉 SSRC {$ssrc}: {$lost} pacotes perdidos (seq {$expectedSeq}-{$sequence})", 'red');
                } elseif ($sequence < $expectedSeq) {
                    // Pacote fora de ordem ou duplicado
                    cli::pcl("⚠️  SSRC {$ssrc}: Pacote fora de ordem seq={$sequence}, esperado={$expectedSeq}", 'yellow');
                }
            }
        }

        $metrics['last_sequence'] = $sequence;

        if ($metrics['total_packets'] > 0) {
            $lossInfo['loss_percentage'] = ($metrics['lost_packets'] / 
                ($metrics['total_packets'] + $metrics['lost_packets'])) * 100;
        }

        return $lossInfo;
    }

    /**
     * Calcula jitter baseado no RFC 3550
     */
    private function calculateJitter(int $ssrc, int $timestamp, float $arrivalTime): array
    {
        $metrics = &$this->ssrcMetrics[$ssrc];
        $jitterInfo = [
            'current_jitter' => 0.0,
            'avg_jitter' => 0.0,
            'max_jitter' => 0.0,
            'jitter_quality' => 'GOOD'
        ];

        if ($metrics['last_timestamp'] !== -1 && $metrics['last_arrival'] > 0) {
            $timeDiff = $timestamp - $metrics['last_timestamp'];
            $arrivalDiff = ($arrivalTime - $metrics['last_arrival']) * 1000; // ms

            $transit = $arrivalDiff - ($timeDiff / 8); // assumindo 8kHz

            if (count($metrics['jitter_samples']) > 0) {
                $lastTransit = end($metrics['jitter_samples']);
                $jitter = abs($transit - $lastTransit);
                $jitterInfo['current_jitter'] = $jitter;

                // Atualizar média móvel do jitter
                $metrics['jitter_samples'][] = $jitter;
                if (count($metrics['jitter_samples']) > $this->adaptationWindow) {
                    array_shift($metrics['jitter_samples']);
                }

                $jitterInfo['avg_jitter'] = array_sum($metrics['jitter_samples']) / count($metrics['jitter_samples']);
                $jitterInfo['max_jitter'] = max($metrics['jitter_samples']);

                // Classificar qualidade do jitter
                if ($jitterInfo['avg_jitter'] < 10) {
                    $jitterInfo['jitter_quality'] = 'EXCELLENT';
                } elseif ($jitterInfo['avg_jitter'] < 20) {
                    $jitterInfo['jitter_quality'] = 'GOOD';
                } elseif ($jitterInfo['avg_jitter'] < 40) {
                    $jitterInfo['jitter_quality'] = 'FAIR';
                } else {
                    $jitterInfo['jitter_quality'] = 'POOR';
                }
            } else {
                $metrics['jitter_samples'][] = 0;
            }
        }

        $metrics['last_timestamp'] = $timestamp;

        return $jitterInfo;
    }

    /**
     * Analisa a qualidade do áudio baseado no payload
     */
    private function analyzeAudioQuality(string $payload): array
    {
        $quality = [
            'energy_level' => 0.0,
            'silence_detected' => false,
            'distortion_suspected' => false,
            'quality_score' => 1.0
        ];

        $payloadLength = strlen($payload);

        // Verificar se há dados suficientes
        if ($payloadLength < 20) {
            $quality['quality_score'] = 0.3;
            $quality['distortion_suspected'] = true;
            return $quality;
        }

        // Calcular energia do sinal (aproximação)
        $energy = 0;
        $sampleCount = min(160, $payloadLength); // Análise de amostra

        for ($i = 0; $i < $sampleCount; $i++) {
            $sample = ord($payload[$i]);
            $energy += $sample * $sample;
        }

        $quality['energy_level'] = sqrt($energy / $sampleCount) / 255.0;

        // Detectar silêncio
        if ($quality['energy_level'] < 0.01) {
            $quality['silence_detected'] = true;
        }

        // Detectar possível distorção (energia muito alta ou padrões anômalos)
        if ($quality['energy_level'] > 0.9) {
            $quality['distortion_suspected'] = true;
            $quality['quality_score'] *= 0.5;
        }

        // Verificar padrões suspeitos no payload
        $repetitions = 0;
        $lastByte = -1;

        for ($i = 0; $i < min(50, $payloadLength); $i++) {
            $currentByte = ord($payload[$i]);
            if ($currentByte === $lastByte) {
                $repetitions++;
            }
            $lastByte = $currentByte;
        }

        // Muitas repetições podem indicar problema
        if ($repetitions > $sampleCount * 0.8) {
            $quality['distortion_suspected'] = true;
            $quality['quality_score'] *= 0.3;
        }

        return $quality;
    }

    /**
     * Detecta problemas de sincronização
     */
    private function detectSyncIssues(int $ssrc, int $timestamp, float $arrivalTime): array
    {
        $syncInfo = [
            'clock_drift' => 0.0,
            'timing_issues' => false,
            'sync_quality' => 'GOOD'
        ];

        $metrics = &$this->ssrcMetrics[$ssrc];

        if ($metrics['last_timestamp'] !== -1 && $metrics['last_arrival'] > 0) {
            $expectedInterval = 0.02; // 20ms para áudio típico
            $actualInterval = $arrivalTime - $metrics['last_arrival'];

            $drift = abs($actualInterval - $expectedInterval) / $expectedInterval;
            $syncInfo['clock_drift'] = $drift * 100; // porcentagem

            if ($drift > 0.1) { // 10% de desvio
                $syncInfo['timing_issues'] = true;
                $syncInfo['sync_quality'] = $drift > 0.2 ? 'POOR' : 'FAIR';
            }
        }

        return $syncInfo;
    }

    /**
     * Calcula qualidade geral baseado em todas as métricas
     */
    private function calculateOverallQuality(array $lossInfo, array $jitterInfo, array $audioQuality, array $syncInfo): array
    {
        $score = 1.0;
        $issues = [];
        $recommendations = [];

        // Penalizar por perda de pacotes
        if ($lossInfo['loss_percentage'] > $this->lossThreshold) {
            $score *= (1 - $lossInfo['loss_percentage'] / 100);
            $issues[] = "PACKET_LOSS_{$lossInfo['loss_percentage']}%";
            $recommendations[] = "Aumentar buffer de rede";
        }

        // Penalizar por jitter alto
        if ($jitterInfo['avg_jitter'] > $this->jitterThreshold) {
            $score *= 0.7;
            $issues[] = "HIGH_JITTER_{$jitterInfo['avg_jitter']}ms";
            $recommendations[] = "Implementar buffer adaptativo";
        }

        // Considerar qualidade do áudio
        $score *= $audioQuality['quality_score'];
        if ($audioQuality['distortion_suspected']) {
            $issues[] = "AUDIO_DISTORTION";
            $recommendations[] = "Verificar codec ou rede";
        }

        // Problemas de sincronização
        if ($syncInfo['timing_issues']) {
            $score *= 0.8;
            $issues[] = "TIMING_ISSUES";
            $recommendations[] = "Verificar sincronização de relógio";
        }

        // Classificar qualidade final
        $qualityLevel = 'EXCELLENT';
        if ($score < 0.9) $qualityLevel = 'GOOD';
        if ($score < 0.7) $qualityLevel = 'FAIR';
        if ($score < 0.5) $qualityLevel = 'POOR';
        if ($score < 0.3) $qualityLevel = 'CRITICAL';

        return [
            'score' => round($score, 3),
            'level' => $qualityLevel,
            'issues' => $issues,
            'recommendations' => $recommendations,
            'needs_adaptation' => $score < 0.7
        ];
    }

    /**
     * Atualiza histórico de pacotes
     */
    private function updatePacketHistory(int $ssrc, array $analysis): void
    {
        $this->packetHistory[$ssrc][] = $analysis;

        // Manter tamanho do histórico sob controle
        if (count($this->packetHistory[$ssrc]) > $this->maxHistorySize) {
            array_shift($this->packetHistory[$ssrc]);
        }
    }

    /**
     * Obtém relatório de qualidade para um SSRC
     */
    public function getQualityReport(int $ssrc): array
    {
        if (!isset($this->ssrcMetrics[$ssrc])) {
            return ['error' => 'SSRC not found'];
        }

        $metrics = $this->ssrcMetrics[$ssrc];
        $recentPackets = array_slice($this->packetHistory[$ssrc], -$this->adaptationWindow);

        $avgLoss = 0;
        $avgJitter = 0;
        $avgQuality = 0;
        $issueCount = ['PACKET_LOSS' => 0, 'HIGH_JITTER' => 0, 'AUDIO_DISTORTION' => 0];

        foreach ($recentPackets as $packet) {
            $avgLoss += $packet['packet_loss']['loss_percentage'];
            $avgJitter += $packet['jitter']['avg_jitter'];
            $avgQuality += $packet['overall_quality']['score'];

            foreach ($packet['overall_quality']['issues'] as $issue) {
                if (strpos($issue, 'PACKET_LOSS') !== false) $issueCount['PACKET_LOSS']++;
                if (strpos($issue, 'HIGH_JITTER') !== false) $issueCount['HIGH_JITTER']++;
                if (strpos($issue, 'AUDIO_DISTORTION') !== false) $issueCount['AUDIO_DISTORTION']++;
            }
        }

        $packetCount = count($recentPackets);
        if ($packetCount > 0) {
            $avgLoss /= $packetCount;
            $avgJitter /= $packetCount;
            $avgQuality /= $packetCount;
        }

        return [
            'ssrc' => $ssrc,
            'total_packets' => $metrics['total_packets'],
            'lost_packets' => $metrics['lost_packets'],
            'avg_loss_percentage' => round($avgLoss, 2),
            'avg_jitter' => round($avgJitter, 2),
            'avg_quality_score' => round($avgQuality, 3),
            'issue_frequency' => $issueCount,
            'recent_window_size' => $packetCount,
            'adaptation_needed' => $avgQuality < 0.7 || $avgJitter > $this->jitterThreshold
        ];
    }

    /**
     * Gera recomendações de adaptação baseado na análise
     */
    public function getAdaptationRecommendations(int $ssrc): array
    {
        $report = $this->getQualityReport($ssrc);
        $recommendations = [];

        if (!isset($report['error'])) {
            if ($report['avg_loss_percentage'] > $this->lossThreshold) {
                $recommendations[] = [
                    'type' => 'BUFFER_INCREASE',
                    'priority' => 'HIGH',
                    'description' => 'Aumentar buffer devido à alta perda de pacotes',
                    'suggested_buffer_ms' => min(200, 50 + ($report['avg_loss_percentage'] * 5))
                ];
            }

            if ($report['avg_jitter'] > $this->jitterThreshold) {
                $recommendations[] = [
                    'type' => 'ADAPTIVE_BUFFER',
                    'priority' => 'MEDIUM',
                    'description' => 'Implementar buffer adaptativo devido ao alto jitter',
                    'suggested_buffer_ms' => min(150, 30 + $report['avg_jitter'])
                ];
            }

            if ($report['avg_quality_score'] < 0.5) {
                $recommendations[] = [
                    'type' => 'CODEC_ADAPTATION',
                    'priority' => 'HIGH',
                    'description' => 'Considerar mudança de codec devido à baixa qualidade',
                    'suggested_codec' => 'G.711' // Mais robusto
                ];
            }

            if ($report['issue_frequency']['AUDIO_DISTORTION'] > 10) {
                $recommendations[] = [
                    'type' => 'ERROR_CORRECTION',
                    'priority' => 'HIGH',
                    'description' => 'Implementar correção de erro devido à distorção frequente'
                ];
            }
        }

        return $recommendations;
    }
}
