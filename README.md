# libspech

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://www.php.net/)
[![Swoole](https://img.shields.io/badge/Swoole-6.0+-green.svg)](https://www.swoole.com/)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](LICENSE.txt)
[![Website](https://img.shields.io/badge/Website-spechshop.com-orange.svg)](https://spechshop.com)

Biblioteca VoIP SIP/RTP em tempo real para PHP, construída com corrotinas Swoole. Faça e receba chamadas telefônicas de PHP, transmita
audio RTP, manipule DTMF e grave áudio.

> **📖 OPEN SOURCE** - Copyright © 2025 Lotus / berzersks
> Licensed under Apache 2.0. Free to use, modify, and distribute.
> **Please respect the creator and contribute at the [official repository](https://github.com/spechshop/libspech)**

## Visão Geral

libspech fornece:

- Recursos de user-agent SIP: registro, configuração/desmontagem de chamadas (INVITE/200/ACK/BYE), autenticação digest
- Canais de mídia RTP/RTCP: receber e enviar quadros de áudio
- API orientada a eventos com callbacks para toque, resposta, desligamento e áudio recebido
- Envio de DTMF (RFC 2833)
- Auxiliares de gravação WAV para PCM capturado
- I/O assíncrono de alto desempenho via Swoole

> 📘 **Nova Documentação**: Veja **[SIGNALING_ARRAYS.md](SIGNALING_ARRAYS.md)** para entender em profundidade como os arrays de sinalização SIP são construídos e processados.

Este README reflete o repositório a partir de 2025-11-24.

## Stack

- Linguagem: PHP (sem Composer neste repositório)
- Framework/runtime: Corrotinas Swoole (incluído nas releases pcg729)
- Protocolos: SIP, RTP/RTCP, SDP, DTMF (RFC 2833)
- Extensões nativas: `bcg729`, `opus`, `psampler` (incluídas nas releases pcg729)

## Requisitos

- Linux/macOS recomendado
- Releases do [berzersks/pcg729](https://github.com/berzersks/pcg729/releases) que incluem PHP 8.4+ com Swoole, bcg729 (baseado no Belladone BCG729), Opus e psampler pré-compilados

## Instalação

Baixe a última release do [berzersks/pcg729](https://github.com/berzersks/pcg729/releases). Esta release inclui todas as extensões necessárias (Swoole, bcg729 baseado no Belladone BCG729, Opus, psampler) pré-compiladas e prontas para uso.

Siga as instruções de instalação fornecidas na release para configurar o ambiente.

## Começando

O repositório inclui um exemplo executável em `example.php`.

### Configuração Inicial

1. Configure suas credenciais SIP no arquivo `.env`:
   ```bash
   cp .env.example .env
   # Edite .env com suas credenciais
   ```

2. Execute o exemplo:
   ```bash
   php example.php
   ```

### Exemplo Mínimo

```php
<?php
use libspech\Sip\trunkController;

include 'plugins/autoloader.php';

\Swoole\Coroutine\run(function () {
    $username = getenv('SIP_USERNAME');
    $password = getenv('SIP_PASSWORD');
    $domain   = getenv('SIP_DOMAIN');
    $host     = gethostbyname($domain);

    $phone = new trunkController($username, $password, $host, 5060);

    if (!$phone->register(2)) {
        throw new \Exception('Falha no registro');
    }

    // Oferecer codec Opus em SDP
    $phone->mountLineCodecSDP('opus/48000/2');

    $phone->onRinging(function ($phone) {
        echo "Tocando...\n";
    });

    $phone->onAnswer(function (trunkController $phone) {
        echo "Atendido. Recebendo mídia...\n";
        $phone->receiveMedia();
        \Swoole\Coroutine::sleep(10);
    });

    $phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) {
        echo "Recebido: " . strlen($pcmData) . " bytes\n";
    });

    $phone->onHangup(function (trunkController $phone) {
        echo "Chamada finalizada\n";
        $phone->close();
    });

    $phone->call('5511999999999');
});
```

### Exemplo Completo (Production-Ready)

Veja o arquivo `example.php` para um exemplo completo que inclui:

```php
<?php
ini_set('memory_limit', '1024M');

use libspech\Cli\cli;
use libspech\Sip\trunkController;

\Swoole\Runtime::enableCoroutine();
include 'plugins/autoloader.php';

\Swoole\Coroutine\run(function () {
    \Swoole\Coroutine::create(function () {
        // Carregar credenciais do .env
        $username = getenv('SIP_USERNAME') ?: '';
        $password = getenv('SIP_PASSWORD') ?: '';
        $domain = getenv('SIP_DOMAIN') ?: 'spechshop.com';
        $host = gethostbyname($domain);

        // Inicializar controlador de tronco SIP
        $phone = new trunkController($username, $password, $host, 5060);

        // Registrar no servidor SIP com 2 tentativas
        if (!$phone->register(2)) {
            throw new \Exception("Erro ao registrar");
        }

        // Definir timeout para 120 segundos
        $phone->defineTimeout(120);
        $audioBuffer = '';

        // Callback: chamada recebida (ringing)
        $phone->onRinging(function ($phone) {
            cli::pcl("Chamada recebida", "yellow");
        });

        // Callback: chamada finalizada (hangup)
        $phone->onHangup(function (trunkController $phone) use (&$audioBuffer) {
            cli::pcl("Chamada finalizada", "red");
            // Salvar áudio capturado em arquivo WAV
            $phone->saveBufferToWavFile('gravado.wav', $audioBuffer);
            $phone->close();
        });

        // Configurar codec Opus (48kHz, estéreo)
        $phone->mountLineCodecSDP('opus/48000/2');

        // Callback: receber áudio PCM
        $phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone) use (&$audioBuffer) {
            cli::pcl(
                "Recebendo áudio: " . strlen($pcmData) . " bytes de {$peer['port']} {$phone->codecName}",
                "blue"
            );
            $audioBuffer .= $pcmData;
        });

        // Callback: chamada atendida
        $phone->onAnswer(function (trunkController $phone) {
            $phone->receiveMedia();           // Iniciar recepção de mídia
            $phone->defineAudioFile('music.wav'); // Definir arquivo de áudio para envio
            cli::pcl("Chamada aceita", "green");
            
            // Aguardar 100ms ou BYE
            \libspech\Sip\interruptibleSleep(100, $phone->receiveBye);
            
            // Enviar DTMF (RFC 2833): número 42017165204 com 160ms de duração
            $phone->send2833(42017165204, 160);
            
            // Aguardar 30ms ou BYE
            \libspech\Sip\interruptibleSleep(30, $phone->receiveBye);
        });

        // Callback: quando recebe pressionamento de tecla (DTMF)
        $phone->onKeyPress(function ($event, $peer) use ($phone) {
            cli::pcl("Digitando: " . $event, "yellow");
        });

        // Fazer chamada para número
        $phone->call('551140040104');
        
        cli::pcl("Script finalizado", "green");
        $phone->close();
    });
});

cli::pcl("Processo encerrado com sucesso", "green");
```

**Recursos principais demonstrados:**

- ✅ Carregar credenciais de variáveis de ambiente (`.env`)
- ✅ Registrar no servidor SIP
- ✅ Configurar codec Opus
- ✅ Gerenciar callbacks de estado (ringing, answer, hangup)
- ✅ Receber e processar áudio PCM
- ✅ Enviar DTMF (RFC 2833)
- ✅ Salvar áudio em arquivo WAV
- ✅ Usar CLI helper para logging colorido
- ✅ Fazer chamadas de saída

## Scripts

- Não há gerenciador de pacotes ou executor de scripts neste repositório. Use o PHP CLI diretamente.
- Ponto de entrada para a demo é `example.php`.

## Variáveis de Ambiente

A biblioteca utiliza variáveis de ambiente para gerenciar credenciais SIP de forma segura:

```bash
SIP_USERNAME=seu_username
SIP_PASSWORD=sua_password
SIP_DOMAIN=sip.example.com
SIP_PORT=5060
SIP_TIMEOUT=120
```

Copie o arquivo `.env.example` para `.env` e preencha com suas credenciais:

```bash
cp .env.example .env
```

As credenciais são carregadas com `getenv()` no código. TODO: documentar qualquer configuração adicional de runtime (proxies, IP público/NAT).

## API de Callbacks

A classe `trunkController` oferece os seguintes callbacks para gerenciar eventos de chamada:

### Eventos de Chamada

```php
// Chamada recebida (ringing)
$phone->onRinging(function ($phone) {
    echo "Tocando...\n";
});

// Chamada atendida
$phone->onAnswer(function (trunkController $phone) {
    $phone->receiveMedia();
    // ... processar áudio ...
});

// Chamada finalizada (hangup)
$phone->onHangup(function (trunkController $phone) {
    $phone->close();
});

// Pressionamento de tecla (DTMF)
$phone->onKeyPress(function ($event, $peer) {
    echo "Dígito recebido: " . $event . "\n";
});
```

### Callbacks de Mídia

```php
// Receber áudio PCM
$phone->onReceivePcm(function ($pcmData, $peer, trunkController $phone) {
    // $pcmData: dados de áudio em formato PCM
    // $peer: endereço IP e porta do servidor RTP remoto
    // $phone: instância do controlador
});

// Alternativa (compatibilidade)
$phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) {
    // Mesmo que onReceivePcm
});
```

### Métodos Principais

```php
// Registro no servidor SIP
$phone->register($retries);

// Fazer chamada de saída
$phone->call($number);

// Configurar codec SDP
$phone->mountLineCodecSDP('opus/48000/2');  // Opus, 48kHz, estéreo
$phone->mountLineCodecSDP('L16/8000');      // Linear PCM, 8kHz

// Gerenciamento de áudio
$phone->receiveMedia();                      // Iniciar recepção
$phone->defineAudioFile('file.wav');         // Definir arquivo para envio
$phone->saveBufferToWavFile('out.wav', $data);

// Enviar DTMF (RFC 2833)
$phone->send2833($number, $duration);       // $duration em ms

// Configuração
$phone->defineTimeout($seconds);             // Timeout em segundos
$phone->close();                             // Finalizar conexão
```

## Estrutura do Projeto

```
libspech/
├── example.php
├── plugins/                           # Núcleo da biblioteca com sistema de autoload automático
│   ├── autoloader.php                 # Autoloader inteligente + carregamento de .env
│   ├── configInterface.json           # Configuração de diretórios para autoload
│   ├── Packet/                        # Renderização de mensagens SIP/SDP
│   │   └── controller/
│   │       └── renderMessages.php     # Gerador de mensagens de resposta SIP
│   └── Utils/
│       ├── cache/                     # Sistema de cache e RPC
│       │   ├── cache.php              # Cache em memória global
│       │   └── rpcClient.php          # Cliente RPC para comunicação
│       ├── cli/                       # Utilitários de linha de comando
│       │   └── cli.php                # Helper de cores e logging
│       ├── libspech/                  # Controladores principais
│       │   ├── trunkController.php    # Classe principal para gerenciar chamadas SIP
│       │   └── functionsTrunkController.php  # Funções auxiliares
│       ├── network/                   # Utilitários de rede
│       │   └── network.php            # Detecção de IP local, validação, alocação de portas
│       └── sip/                       # Stack SIP/RTP completo
│           ├── sip.php                # Parser de mensagens SIP (INVITE, REGISTER, BYE)
│           ├── rtpChannel.php         # Gerenciamento de canais RTP (headers, codecs)
│           ├── mediaChannel.php       # Recepção/envio de áudio com suporte a múltiplos codecs
│           ├── rtpc.php               # Controle RTP/RTCP (stat reporting)
│           ├── DtmfEvent.php          # Eventos DTMF (RFC 2833)
│           └── AdaptiveBuffer.php     # Buffer adaptativo para mitigação de jitter
├── stubs/                             # Stubs IDE para extensões nativas
│   ├── bcg729Channel.php              # Extensão bcg729 (G.729 codec)
│   ├── opusChannel.php                # Extensão opus (Opus codec)
│   └── psampler.php                   # Extensão psampler (reamostragem de áudio)
│       ├── cache/
│       │   ├── cache.php
│       │   └── rpcClient.php
│       ├── cli/cli.php                # Utilitários auxiliares CLI
│       ├── libspech/trunkController.php  # Controlador principal de chamadas (namespace libspech\\Sip)
│       ├── network/network.php
│       └── sip/
│           ├── AdaptiveBuffer.php
│           ├── DtmfEvent.php
│           ├── mediaChannel.php
│           ├── rtpChannel.php
│           ├── rtpc.php
│           ├── sip.php
│           └── trunkController.php    # Controlador legado/alt (mantido para compatibilidade)
├── stubs/                             # Stubs IDE para extensões opcionais
│   ├── bcg729Channel.php
│   ├── opusChannel.php
│   └── psampler.php
├── LICENSE.txt
├── README.md
└── SECURITY.md
```

### Sistema de Autoload

O arquivo `autoloader.php` realiza três operações ao ser incluído:

1. **Carregamento de .env**: Procura por arquivo `.env` e o cria a partir de `.env.example` se não existir
2. **Parsing de variáveis de ambiente**: Lê o arquivo `.env` e popula com `putenv()`
3. **Autoload automático**: Lê `configInterface.json` e inclui todos os arquivos PHP dos diretórios listados

```php
// configInterface.json define quais diretórios autocarregar
{
  "autoload": [
    "Utils/cache",      // Sistema de cache
    "Utils/cli",        // Utilitários CLI
    "Utils/sip",        // Stack SIP/RTP
    "Utils/libspech",   // Controladores principais
    "Utils/network",    // Utilitários de rede
    "Packet/controller" // Renderizadores de mensagens
  ],
  "reloadCaseFileModify": []  // TODO: hot reload em desenvolvimento
}
```

**Namespace:** A classe principal usa `libspech\Sip\trunkController`:

```php
use libspech\Sip\trunkController;  // Correto - namespace na classe
include 'plugins/autoloader.php';
```

## Documentação dos Módulos

### Core Modules

#### `plugins/Utils/libspech/trunkController.php` (Controlador Principal)

Classe `libspech\Sip\trunkController` - Orquestrador central para gerenciar chamadas SIP.

**Responsabilidades principais:**
- Registro no servidor SIP (com suporte a autenticação Digest)
- Gerenciamento completo do ciclo de vida da chamada (INVITE → ACK → BYE)
- Configuração de callbacks para eventos (ringing, answer, hangup, DTMF)
- Gerenciamento de canais RTP/RTCP
- Recepção e envio de áudio

**Propriedades importantes:**
```php
public \Swoole\Coroutine\Socket $socket;        // Socket UDP para SIP
public bool $isRegistered;                       // Estado de registro
public bool $callActive;                         // Chamada em andamento?
public string $codecName;                        // Codec negociado
public array $rtpChans;                          // Canais RTP por SSRC
public int $audioReceivePort;                    // Porta para RTP
```

**Métodos principais:**
```php
register(int $retries): bool                     // Registrar no servidor SIP
call(string $number): void                       // Iniciar chamada outbound
receiveMedia(): void                             // Iniciar recepção de mídia RTP
send2833(int $code, int $duration): void        // Enviar DTMF (RFC 2833)
defineAudioFile(string $path): void             // Definir arquivo para envio
saveBufferToWavFile(string $path, string $data) // Salvar áudio capturado em WAV
mountLineCodecSDP(string $codec): void          // Configurar codec SDP
defineTimeout(int $seconds): void               // Timeout para chamada
close(): void                                    // Finalizar conexão
```

#### `plugins/Utils/sip/sip.php` (Parser SIP)

Classe `libspech\Sip\sip` - Parser de mensagens SIP/SDP.

**Responsabilidades:**
- Parse de mensagens SIP (INVITE, 200 OK, BYE, REGISTER, etc.)
- Extração de headers SIP
- Parse de ofertas/respostas SDP
- Validação de autenticação Digest

**Métodos principais:**
```php
sip::parse(string $rawMessage): array           // Parser mensagem SIP bruta
sip::extractUri(string $uri): array             // Extrair componentes de URI SIP
sip::normalizeArrayKey(string $key, ...): array // Normalizar chaves de headers
```

**Exemplo de uso:**
```php
$message = "INVITE sip:user@domain.com SIP/2.0\r\n...";
$parsed = sip::parse($message);

// $parsed contém:
// - 'method' => 'INVITE'
// - 'headers' => [...] 
// - 'sdp' => [...] (se houver SDP no body)
```

#### `plugins/Utils/sip/mediaChannel.php` (Canal de Mídia)

Classe `libspech\Rtp\MediaChannel` - Gerenciamento de recepção/envio de áudio RTP.

**Responsabilidades:**
- Recepção de pacotes RTP de múltiplos peers
- Decodificação de áudio (G.729, Opus, PCMU, PCMA)
- Detecção de voz (VAD - Voice Activity Detection)
- Detecção de DTMF (RFC 2833)
- Buffer adaptativo para mitigação de jitter

**Métodos principais:**
```php
onReceive(callable $callback): void              // Callback ao receber áudio
onDtmf(callable $callback): void                 // Callback DTMF recebido
onVadChange(callable $callback): void            // Callback detecção de voz
enableAdaptation(bool $useBuffer): void          // Ativar buffer adaptativo
disableAdaptation(): void                        // Desativar buffer adaptativo
recordAudio(string $path): void                  // Gravar áudio em arquivo
```

**Propriedades principais:**
```php
public array $members;                           // Peers RTP conectados
public bool $vadEnabled;                         // VAD ativado?
public bool $isVoiceActive;                      // Voz detectada?
public bool $recordingEnabled;                   // Gravação ativa?
public bcg729Channel $channelEncode;             // Encoder G.729
public bcg729Channel $channelDecode;             // Decoder G.729
public ?opusChannel $opusChannel;                // Encoder/Decoder Opus
```

#### `plugins/Utils/sip/rtpChannel.php` (Canal RTP)

Classe `libspech\Rtp\rtpChannel` - Montagem/desmontagem de pacotes RTP.

**Responsabilidades:**
- Construção de headers RTP (RFC 3550)
- Gerenciamento de sequence number e timestamp
- Suporte a múltiplos payload types (codecs)
- Eventos DTMF (RFC 2833)

**Constantes de payload:**
```php
const PAYLOAD_PCMU = 0;      // G.711 µ-law
const PAYLOAD_PCMA = 8;      // G.711 A-law
const PAYLOAD_G729 = 18;     // G.729
const PAYLOAD_DTMF = 101;    // DTMF (RFC 2833)
```

**Métodos principais:**
```php
__construct(int $payloadType, int $sampleRate, int $packetTimeMs, ?int $ssrc)
encodeFrame(string $audioData): string          // Encapsular áudio em RTP
decodeFrame(string $rtpPacket): string          // Extrair áudio de RTP
sendDtmfEvent(DtmfEvent $event): string         // Montar evento DTMF
```

#### `plugins/Utils/sip/DtmfEvent.php` (Eventos DTMF)

Classe `libspech\Rtp\DtmfEvent` - Representação de eventos DTMF (RFC 2833).

**Constantes DTMF:**
```php
const DTMF_0 = 0;   // Dígito 0
const DTMF_1 = 1;   // Dígito 1
// ... 2-9 ...
const DTMF_STAR = 10;  // Símbolo *
const DTMF_HASH = 11;  // Símbolo #
const DTMF_A = 12;     // Letra A
const DTMF_B = 13;     // Letra B
const DTMF_C = 14;     // Letra C
const DTMF_D = 15;     // Letra D
```

**Uso:**
```php
$dtmf = new DtmfEvent(DtmfEvent::DTMF_5, 10, 160);
$phone->send2833($dtmf);
```

#### `plugins/Utils/sip/AdaptiveBuffer.php` (Buffer Adaptativo)

Classe `libspech\Rtp\AdaptiveBuffer` - Mitigação de jitter e perda de pacotes.

**Responsabilidades:**
- Buffering automático de pacotes RTP
- Adaptação dinâmica do tamanho do buffer baseado em jitter
- Detecção de underruns/overruns
- Métricas em tempo real

**Métodos principais:**
```php
enable(): void                                   // Ativar buffer
disable(): void                                  // Desativar buffer
push(mixed $data): bool                          // Adicionar pacote
pop(): ?mixed                                    // Remover pacote
getMetrics(): array                              // Obter estatísticas
```

#### `plugins/Utils/sip/rtpc.php` (Controle RTP/RTCP)

Classe `libspech\Rtp\rtpc` - Gerenciamento de RTCP (relatório de estatísticas).

**Responsabilidades:**
- Envio de relatórios RTCP SR (Sender Report)
- Recebimento de relatórios RTCP RR (Receiver Report)
- Tracking de estatísticas de mídia (loss, jitter, rtt)

### Utility Modules

#### `plugins/Utils/network/network.php` (Utilitários de Rede)

Classe `libspech\Network\network` - Detecção de IP local, validação e alocação de portas.

**Métodos principais:**
```php
getLocalIp(): ?string                            // Obter IP local não-loopback
isPrivateIp(string $ip): bool                    // Verificar se IP é privado
isPublicIp(string $ip): bool                     // Verificar se IP é público
allocateRtpPort(): int                           // Alocar porta RTP (10000-62000)
```

#### `plugins/Utils/cache/cache.php` (Cache em Memória)

Classe `libspech\Cache\cache` - Armazenamento de estado em `$GLOBALS`.

**Métodos principais:**
```php
get(string $key): mixed                          // Obter valor
set(string $key, mixed $value): void             // Definir valor
join(string $key, mixed $value): bool            // Adicionar a array
subJoin(string $key, string $subKey, mixed $v)   // Adicionar a array aninhado
arrayShift(string $key): mixed                   // Pop do início de array
```

#### `plugins/Utils/cli/cli.php` (Utilitários CLI)

Classe `libspech\Cli\cli` - Logging colorido e menu CLI.

**Métodos principais:**
```php
static color(string $color, string $message): string    // Colorizar texto
static pcl(string $message, string $color = 'white')    // Print colorido
```

**Cores suportadas:**
```php
'black', 'red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white',
'bold_black', 'bold_red', 'bold_green', 'bold_yellow', ...
```

#### `plugins/Packet/controller/renderMessages.php` (Renderizador SIP)

Classe `libspech\Packet\renderMessages` - Gerador de mensagens SIP de resposta.

**Métodos principais:**
```php
generateBye(array $headers200): array            // Gerar mensagem BYE
respondUserNotFound(array $headers): string      // Resposta 404
baseResponse(array $headers, string $code): string  // Resposta genérica
generateInviteResponse(array $headers): array    // Resposta 200 OK para INVITE
```

## Codecs

Payloads suportados/disponíveis no codebase:

## Codecs

Payloads suportados/disponíveis no codebase:

| Codec                  | Tipo de Payload | Taxa de Amostragem | Status   | Notas/Extensão                                  |
|------------------------|-----------------|---------------------|----------|-------------------------------------------------|
| PCMU (G.711 µ-law)     | 0               | 8 kHz               | Integrado | Nenhuma extensão extra necessária               |
| PCMA (G.711 A-law)     | 8               | 8 kHz               | Integrado | Nenhuma extensão extra necessária               |
| G.729                  | 18              | 8 kHz               | Integrado | Incluído na release pcg729 (baseado no Belladone BCG729) |
| Opus                   | 111             | 48 kHz              | Integrado | Incluído na release pcg729                      |
| L16 (Linear PCM)       | 96              | 8 kHz               | Integrado | psampler incluído para reamostragem            |
| telephone-event (DTMF) | 101             | 8 kHz               | Integrado | RFC 2833 para sinalização DTMF                 |

Notas:

- Múltiplos codecs podem ser oferecidos via SDP. Use `mountLineCodecSDP()` para ajustar preferências.
- Alguns valores de tipo de payload podem variar dependendo da negociação; verifique com seu provedor.

## Arquitetura e Fluxo de Dados

### Fluxo de Uma Chamada Outbound (Enviada)

```
trunkController::call()
    ↓
1. Gerar Call-ID único
2. Montar INVITE (SIP)
3. Negociar SDP (codecs, porta RTP)
4. Enviar INVITE para servidor SIP
    ↓
    ← Receber 100 Trying (opcional)
    ← Receber 180 Ringing (callback onRinging)
    ↓
    ← Receber 200 OK com SDP resposta
5. Extrair IP/porta RTP remoto de SDP
6. Enviar ACK
    ↓
[Chamada conectada]
    ↓
trunkController::receiveMedia()
    ↓
MediaChannel::start()
    ↓
Loop infinito:
  ├─ Receber pacote RTP em socket UDP
  ├─ rtpChannel::decodeFrame() → áudio bruto
  ├─ Callback onReceivePcm() → usuário processa áudio
  ├─ Detectar DTMF (RFC 2833) → callback onKeyPress()
  ├─ Detectar voz (VAD) → callback onVadChange()
  └─ Buffer adaptativo mitiga jitter
    ↓
[Usuário envia BYE ou timeout]
    ↓
renderMessages::generateBye()
    ↓
Enviar BYE → Servidor SIP
    ↓
Callback onHangup()
```

### Fluxo de Uma Chamada Inbound (Recebida)

```
1. Servidor SIP recebe INVITE para seu número
2. Redireciona para seu IP:porta local
3. trunkController socket recebe INVITE
    ↓
4. sip::parse() processa INVITE + SDP
5. Extrair codec, IP/porta remoto
6. Callback onRinging()
    ↓
[Usuário aguarda decisão]
    ↓
7. Gerar SDP de resposta (codec negocia)
8. Enviar 200 OK com SDP
9. Receber ACK
    ↓
[Chamada conectada]
    ↓
Callback onAnswer()
    ↓
MediaChannel inicia recepção (similar ao outbound)
    ↓
Callback onHangup() quando recebe BYE
```

### Pilha de Módulos

```
┌─────────────────────────────────────────────┐
│       Aplicação do Usuário                  │
│  (example.php com callbacks)                │
└─────────────────┬───────────────────────────┘
                  │
┌─────────────────▼───────────────────────────┐
│   trunkController (Orquestrador)            │
│   - Ciclo de vida da chamada                │
│   - Gerenciamento de estado                 │
│   - Callbacks de eventos                    │
└─────────────────┬───────────────────────────┘
                  │
        ┌─────────┴──────────┐
        │                    │
┌───────▼──────┐      ┌──────▼───────┐
│  sip.php     │      │ mediaChannel │
│  (Parser)    │      │  (RTP/RTCP)  │
│              │      │              │
│ - INVITE     │      │ - rtpChannel │
│ - 200 OK     │      │ - DtmfEvent  │
│ - BYE        │      │ - AdaptiveBuffer
│ - REGISTER   │      │ - VAD        │
│ - SDP parse  │      └──────────────┘
└───────┬──────┘
        │
┌───────▼──────────────────────────────┐
│   Socket UDP (Swoole)                │
│   - Envio/recepção de pacotes SIP    │
│   - Envio/recepção de pacotes RTP    │
└──────────────────────────────────────┘
```

### Integração com Swoole Coroutines

Toda a I/O é não-bloqueante usando corrotinas Swoole:

```php
\Swoole\Coroutine\run(function () {
    \Swoole\Coroutine::create(function () {
        // Cada coroutine = contexto de chamada isolado
        
        // I/O não-bloqueante
        $phone->register(2);        // Yield até resposta
        $phone->call($number);      // Yield até resposta
        $phone->receiveMedia();     // Yield em loop RTP
        
        // Nenhum thread, nenhum callback hell - código sequencial
    });
});
```

**Benefícios:**
- ✅ Milhares de chamadas simultâneas em um thread
- ✅ Zero overhead de sincronização
- ✅ Código linear e fácil de entender
- ✅ Integração nativa com extensões Swoole (timers, queues, etc.)

## Notas de Uso

- Rede/NAT: certifique-se de que o IP local e portas que a biblioteca vincula sejam alcançáveis pelo peer SIP. STUN/travessia NAT
  não está incluída. TODO: documentar utilitários auxiliares ou melhores práticas para ambientes NAT.
- Segurança: esta biblioteca foca no SIP básico sobre UDP. TLS/SRTP não estão documentados aqui. TODO: esclarecer status de suporte TLS/SRTP.

## Exemplos Avançados

### Gravando Áudio de Múltiplas Chamadas

```php
<?php
use libspech\Sip\trunkController;

\Swoole\Coroutine\run(function () {
    $calls = [
        '5511999999999',
        '5511888888888',
    ];
    
    foreach ($calls as $number) {
        \Swoole\Coroutine::create(function () use ($number) {
            $phone = new trunkController($username, $password, $host, 5060);
            $phone->register(2);
            
            $audioBuffer = '';
            
            $phone->onReceivePcm(function ($pcmData, $peer, $p) use (&$audioBuffer) {
                $audioBuffer .= $pcmData;
            });
            
            $phone->onHangup(function ($p) use (&$audioBuffer, $number) {
                $filename = "call_{$number}_" . date('YmdHis') . ".wav";
                $p->saveBufferToWavFile($filename, $audioBuffer);
                echo "Gravado: $filename\n";
            });
            
            $phone->call($number);
        });
    }
});
```

### Detecção de DTMF e Menu IVR

```php
<?php
$phone->onKeyPress(function ($digit, $peer) use ($phone) {
    switch ($digit) {
        case '1':
            echo "Opção 1 pressionada\n";
            $phone->defineAudioFile('menu_option1.wav');
            break;
        case '2':
            echo "Opção 2 pressionada\n";
            $phone->defineAudioFile('menu_option2.wav');
            break;
        case '*':
            echo "Retornar ao menu\n";
            $phone->defineAudioFile('main_menu.wav');
            break;
        case '#':
            echo "Finalizar chamada\n";
            $phone->close();
            break;
    }
});
```

### Processamento em Tempo Real com VAD

```php
<?php
$mediaChannel = new MediaChannel($callId);
$mediaChannel->enableVAD();  // Voice Activity Detection

$mediaChannel->onVadChange(function ($isVoiceActive) {
    if ($isVoiceActive) {
        echo "Voz detectada\n";
    } else {
        echo "Silêncio\n";
    }
});

$mediaChannel->onReceive(function ($pcmData, $peer) {
    if ($mediaChannel->isVoiceActive) {
        // Processar apenas quando há voz
        processAudio($pcmData);
    }
});
```

## Testes

- Não há testes automatizados no repositório no momento.
- TODO: adicionar testes unitários/integração para parsing de mensagens SIP, timing RTP, DTMF e fluxos de chamadas de exemplo.

## Licença

This project is licensed under the **Apache License 2.0**.

**Copyright © 2025 Lotus / berzersks**
**Website: [https://spechshop.com](https://spechshop.com)**
**Official Repository: [https://github.com/spechshop/libspech](https://github.com/spechshop/libspech)**

### Important Notice to the Community

This is **open source software**. You are free to use, modify, and distribute it under the Apache 2.0 license. However, we kindly ask that you:

- ✅ **Respect the creator**: Maintain attribution to Lotus (berzersks) in all derivative works
- ✅ **Keep copyright notices**: Do not remove or alter copyright notices and attributions
- ✅ **Unite the community**: Consider contributing improvements to the official repository rather than creating fragmented forks
- ✅ **Submit pull requests**: Help make this project better for everyone by contributing at [github.com/spechshop/libspech](https://github.com/spechshop/libspech)
- ✅ **Reference the creator**: Credit the original author when discussing or referencing this software

A unified community is stronger and advances faster together. Thank you for helping build a respectful and collaborative open source project!

See [LICENSE.txt](LICENSE.txt) for full license terms.

### Third-Party Dependencies

Third-party components are under their respective licenses:
- Swoole: Apache License 2.0
- bcg729: GNU GPL v3.0
- Opus: BSD License
- psampler: See repository for details


### Important Notice to the Community

This is **open source software**. You are free to use, modify, and distribute it under the Apache 2.0 license. However, we kindly ask that you:

- ✅ **Respect the creator**: Maintain attribution to Lotus (berzersks) in all derivative works
- ✅ **Keep copyright notices**: Do not remove or alter copyright notices and attributions
- ✅ **Unite the community**: Consider contributing improvements to the official repository rather than creating fragmented forks
- ✅ **Submit pull requests**: Help make this project better for everyone by contributing at [github.com/spechshop/libspech](https://github.com/spechshop/libspech)
- ✅ **Reference the creator**: Credit the original author when discussing or referencing this software

A unified community is stronger and advances faster together. Thank you for helping build a respectful and collaborative open source project!

See [LICENSE.txt](LICENSE.txt) for full license terms.

### Third-Party Dependencies

Third-party components are under their respective licenses:
- Swoole: Apache License 2.0
- bcg729: GNU GPL v3.0
- Opus: BSD License
- psampler: See repository for details

Review their license files before use in production.
