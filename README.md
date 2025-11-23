# libspech

Uma biblioteca SIP (Session Initiation Protocol) baseada em PHP para construir aplicações VoIP usando corrotinas Swoole. Esta biblioteca fornece funcionalidade de controlador de trunking para registro SIP, gerenciamento de chamadas e streaming de áudio em tempo real.

## Visão Geral

libspech é uma biblioteca SIP abrangente que permite que aplicações PHP:
- Registrar com servidores SIP
- Fazer e receber chamadas VoIP
- Lide com streaming de áudio em tempo real com suporte a vários codecs (PCMU, PCMA, G.729)
- Gerenciar estados de chamada (tocando, atender, desligar)
- Processar eventos DTMF
- Gerenciar detecção de qualidade de áudio e buffering adaptativo
- Suportar protocolos RTP/RTCP

## Requisitos

- **PHP**: 8.4+ (testado em PHP 8.4.13)
- **Extensões**:
  - Swoole (para corrotinas e rede assíncrona)
  - bcg729 (opcional, para suporte ao codec G.729)
- **Rede**: Acesso à porta UDP para SIP (padrão 5060) e portas RTP

## Instalação

### Clonar o Repositório

```bash
git clone https://github.com/berzersks/libspech.git
cd libspech
```

### Instalar Dependências

<!-- TODO: Adicionar composer.json se o Composer for usado para gerenciamento de dependências -->
Atualmente, o projeto usa um autoloader personalizado. Certifique-se de que a extensão Swoole esteja instalada:

```bash
# Install Swoole via PECL
pecl install swoole

# Or compile from source
# See: https://github.com/swoole/swoole-src
```

## Uso

### Exemplo Básico

Veja `example.php` para um exemplo completo e funcional:

```php
<?php

include 'plugins/autoloader.php';

\Swoole\Coroutine\run(function () {
    // Configure SIP credentials
    $username = 'your_sip_username';
    $password = 'your_sip_password';
    $domain = 'your_sip_domain.com';
    $host = gethostbyname($domain);

    // Create trunk controller
    $phone = new trunkController(
        $username,
        $password,
        $host,
        5060,
    );

    // Register with SIP server
    if (!$phone->register(2)) {
        throw new \Exception("Registration failed");
    }

    // Set up event handlers
    $phone->onRinging(function ($call) {
        echo "Call is ringing...\n";
    });

    $phone->onAnswer(function ($call) {
        echo "Call answered!\n";
    });

    $phone->onHangup(function ($call) {
        echo "Call ended.\n";
    });

    $phone->onReceiveAudio(function ($audioData) {
        echo strlen($audioData) . " bytes received\n";
    });

    // Make a call
    $phone->prefix = 4479;
    $phone->call('551140040104');
});
```

## Estrutura do Projeto

```
libspech/
├── plugins/                      # Módulos principais da biblioteca
│   ├── autoloader.php           # Autoloader customizado
│   ├── configInterface.json     # Configuração do autoloader
│   ├── Packet/                  # Manipuladores de pacotes SIP
│   │   └── controller/
│   │       └── renderMessages.php    # Renderização de mensagens SIP
│   └── Utils/                   # Classes utilitárias
│       ├── cache/               # Mecanismos de cache
│       │   ├── cache.php        # Cache global
│       │   └── rpcClient.php    # Cliente RPC
│       ├── cli/                 # Utilitários CLI
│       │   └── cli.php          # Output colorido no console
│       ├── network/             # Utilitários de rede
│       │   └── network.php      # Resolução de IP e portas
│       └── sip/                 # Classes core do SIP
│           ├── trunkController.php    # Controlador de trunking principal
│           ├── sip.php                # Parser e render de mensagens SIP
│           ├── rtpChannels.php        # Criação de pacotes RTP
│           └── rtpc.php               # Parser de pacotes RTP
├── stubs/                       # Stubs para autocomplete de IDE
├── example.php                  # Exemplo de uso
└── README.md                    # Este arquivo
```

## Scripts

### Executar Exemplo

```bash
php example.php
```

## Configuração

### Configuração do Autoloader

O autoloader é configurado via `plugins/configInterface.json`:

```json
{
  "autoload": [
    "Utils/cache",
    "Utils/cli",
    "Utils/sip",
    "Utils/network",
    "Packet/controller"
  ],
  "reloadCaseFileModify": []
}
```

### Variáveis de Ambiente

<!-- TODO: Documentar variáveis de ambiente necessárias, se houver -->
Atualmente, nenhuma variável de ambiente é necessária. A configuração é feita programaticamente.

## Codecs Suportados

A biblioteca suporta os seguintes codecs de áudio:

- **PCMU (G.711 μ-law)** - Tipo de carga útil 0
- **PCMA (G.711 A-law)** - Tipo de carga útil 8
- **G.729** - Tipo de carga útil 18 (requer a extensão bcg729)
- **telephone-event (DTMF)** - Tipo de payload 101

## Teste

<!-- TODO: Adicionar framework de testes e instruções -->
Framework de teste ainda não implementado.

## Funcionalidades

- ✅ Registro SIP com autenticação (digest MD5)
- ✅ Iniciação de chamada de saída
- ✅ Streaming de áudio RTP
- ✅ Suporte RTCP
- ✅ Suporte a múltiplos codecs (PCMU, PCMA, G.729)
- ✅ Arquitetura orientada a eventos (callbacks para ringing, answer, hangup, audio)
- ✅ Tratamento de mensagens SIP (INVITE, ACK, BYE, etc.)

## Limitações conhecidas

- Apenas IPv4 (suporte a IPv6 não implementado)
- Sem suporte SRTP/TLS

## Contribuindo

<!-- TODO: Adicionar diretrizes de contribuição -->
Contribuições são bem-vindas! Por favor, envie pull requests para o repositório.

## Licença

<!-- TODO: Adicionar arquivo de licença e especificar o tipo de licença -->
Informações de licença não especificadas. Por favor, contate o autor para detalhes de licenciamento.

## Créditos

Repositório: https://github.com/berzersks/libspech

---

****Nota**: Este é um projeto em desenvolvimento ativo. Algumas funcionalidades podem estar incompletas ou sujeitas a alterações.
