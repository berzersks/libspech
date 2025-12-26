# Documentação de Arrays de Sinalização SIP

## Visão Geral

Os arrays de sinalização são estruturas de dados fundamentais na biblioteca `libspech` que representam mensagens SIP. Eles definem como as mensagens são construídas, processadas e transmitidas através do protocolo SIP.

## Estrutura Base de um Array de Sinalização

Todo array de sinalização SIP segue uma estrutura padronizada:

```php
[
    "method" => "MÉTODO_SIP",              // INVITE, REGISTER, BYE, ACK, etc.
    "methodForParser" => "REQUEST_LINE",   // Linha de requisição HTTP-like
    "headers" => [                         // Headers SIP (sempre arrays)
        "VIA" => ["SIP/2.0/UDP ..."],
        "From" => ["<sip:...>"],
        "To" => ["<sip:...>"],
        "Call-ID" => ["uuid@host"],
        "CSeq" => ["1 INVITE"],
        // ... mais headers ...
    ],
    "sdp" => [                             // Opcional: Session Description Protocol
        "v" => ["0"],
        "o" => ["..."],
        "c" => ["IN IP4 ..."],
        // ... mais atributos SDP ...
    ],
    "body" => "..."                        // Opcional: corpo da mensagem
]
```

### Pontos Importantes:

1. **method**: O método SIP (sempre string única)
2. **methodForParser**: A linha exata que será usada no parsing (ex: "INVITE sip:...")
3. **headers**: Um array associativo onde:
   - Chaves = nomes dos headers SIP
   - Valores = **sempre arrays** (mesmo para valores únicos)
4. **sdp**: Opcional - presente apenas em mensagens com corpo SDP
5. **body**: Opcional - texto adicional/corpo da mensagem

---

## Arrays de Métodos SIP Principais

### 1. INVITE (Iniciar Chamada)

Construído por: `modelInvite(string $to, string $prefix = ""): array`

```php
[
    "method" => "INVITE",
    "methodForParser" => "INVITE sip:5511999999999@sip.provider.com SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5062;branch=z9hG4bK64d" . bin2hex(random_bytes(8)) . ";rport"
        ],
        "From" => [
            "<sip:1000@sip.provider.com:5060>;tag=" . bin2hex(random_bytes(10))
        ],
        "To" => [
            "<sip:5511999999999@sip.provider.com:5060>"
        ],
        "Call-ID" => [
            "abc123def456@192.168.1.100"
        ],
        "CSeq" => [
            "1 INVITE"  // Sequence number + método
        ],
        "Contact" => [
            "<sip:1000@192.168.1.100:5062>"
        ],
        "User-Agent" => [
            "SPECHSHOP LIB"
        ],
        "Max-Forwards" => [
            "70"
        ],
        "Content-Type" => [
            "application/sdp"  // Indica presença de SDP no body
        ],
        "Allow" => [
            "INVITE,ACK,BYE,CANCEL,OPTIONS,NOTIFY,MESSAGE,REFER"
        ],
        "Supported" => [
            "gruu,replaces"
        ],
        "P-Preferred-Identity" => [
            "\"1000\" <sip:1000@192.168.1.100:5062>"
        ]
    ],
    "sdp" => [
        "v" => ["0"],                                    // Versão SDP
        "o" => ["1234567890 0 0 IN IP4 192.168.1.100"],// Originador
        "s" => ["SPECHSHOP LIB"],                        // Nome da sessão
        "c" => ["IN IP4 192.168.1.100"],                // Conexão
        "t" => ["0 0"],                                  // Timing
        "m" => ["audio 10000 RTP/AVP 0 18 101"],        // Mídia (port codec1 codec2...)
        "a" => [                                         // Atributos
            "ssrc:1234567890 cname:1000@192.168.1.100",
            "rtpmap:0 PCMU/8000",
            "rtpmap:18 G729/8000",
            "fmtp:18 annexb=no",
            "rtpmap:101 telephone-event/8000",
            "fmtp:101 0-16",
            "ptime:20",
            "sendrecv"
        ]
    ]
]
```

**Uso:**
```php
$modelInvite = $phone->modelInvite('5511999999999');
$phone->socket->sendto($host, $port, sip::renderSolution($modelInvite));
```

**Headers Críticos:**
- `Via`: Identifica o sender, usado para routing de respostas
- `From`: Identificação do remetente (deve incluir tag)
- `To`: Identificação do destinatário
- `Call-ID`: Identificador único da sessão
- `CSeq`: Sequence number para ordenação de mensagens
- `Contact`: Onde enviar respostas/requests posteriores

---

### 2. REGISTER (Registrar no Servidor SIP)

Construído por: `modelRegister(): array`

```php
[
    "method" => "REGISTER",
    "methodForParser" => "REGISTER sip:sip.provider.com SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5062;branch=z9hG4bK-" . bin2hex(random_bytes(4))
        ],
        "From" => [
            "<sip:1000@sip.provider.com>;tag=" . bin2hex(random_bytes(8))
        ],
        "To" => [
            "<sip:1000@sip.provider.com>"
        ],
        "Call-ID" => [
            "register-abc123@192.168.1.100"
        ],
        "CSeq" => [
            "1 REGISTER"
        ],
        "Contact" => [
            "<sip:1000@192.168.1.100:5062>"
        ],
        "User-Agent" => [
            "SPECHSHOP LIB"
        ],
        "Expires" => [
            "3600"  // Tempo de expiração em segundos
        ],
        "Max-Forwards" => [
            "70"
        ],
        "Allow" => [
            "INVITE, ACK, BYE, CANCEL, OPTIONS, MESSAGE, INFO, REGISTER"
        ],
        "Content-Length" => [
            "0"
        ]
    ]
]
```

**Uso:**
```php
$modelRegister = $phone->modelRegister();
$phone->socket->sendto($host, $port, sip::renderSolution($modelRegister));
```

**Com Autenticação Digest:**
Após receber `401 Unauthorized` com desafio:

```php
$modelRegister["headers"]["Authorization"] = [
    "Digest username=\"1000\", " .
    "realm=\"sip.provider.com\", " .
    "nonce=\"abc123def456\", " .
    "uri=\"sip:sip.provider.com\", " .
    "response=\"" . sip::generateResponse(...) . "\""
];
$modelRegister["headers"]["CSeq"] = ["2 REGISTER"];  // Incrementar CSeq
```

---

### 3. BYE (Finalizar Chamada)

Construído por: `renderMessages::generateBye(array $headers200): array`

```php
[
    "method" => "BYE",
    "methodForParser" => "BYE sip:1000@192.168.1.100 SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5062;branch=z9hG4bK-" . bin2hex(random_bytes(4))
        ],
        "From" => [
            "<sip:1000@sip.provider.com>;tag=abc123"
        ],
        "To" => [
            "<sip:5511999999999@sip.provider.com>;tag=def456"
        ],
        "Call-ID" => [
            "abc123def456@192.168.1.100"
        ],
        "CSeq" => [
            "2 BYE"  // CSeq incrementado
        ],
        "Contact" => [
            "<sip:s@192.168.1.100:5062>"
        ],
        "Content-Length" => [
            "0"
        ]
    ]
]
```

**Características:**
- Usa CSeq incrementado do último INVITE/ACK
- Mantém Call-ID, From, To idênticos
- Remove headers Authorization (se presentes)
- Sem corpo (Content-Length: 0)

---

### 4. ACK (Confirmar Recepção)

Construído por: `ackModel(array $headers): array`

```php
[
    "method" => "ACK",
    "methodForParser" => "ACK sip:contact@sip.provider.com:5060 SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5062;branch=" . bin2hex(random_bytes(8))
        ],
        "From" => [
            "<sip:1000@sip.provider.com>;tag=abc123"
        ],
        "To" => [
            "<sip:5511999999999@sip.provider.com>;tag=def456"
        ],
        "Call-ID" => [
            "abc123def456@192.168.1.100"
        ],
        "CSeq" => [
            "1 ACK"
        ],
        "Max-Forwards" => [
            "70"
        ]
    ]
]
```

**Notas:**
- ACK é sempre enviado para completar handshake 2xx
- Não retorna resposta (nenhuma resposta para ACK)
- Método, From, To devem ser exatamente iguais ao INVITE

---

### 5. CANCEL (Cancelar Chamada)

Construído por: `getModelCancel($called = false): array`

```php
[
    "method" => "CANCEL",
    "methodForParser" => "CANCEL sip:5511999999999@sip.provider.com SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5062;branch=z9hG4bK-" . bin2hex(random_bytes(4))
        ],
        "From" => [
            "<sip:1000@sip.provider.com>;tag=abc123"
        ],
        "To" => [
            "<sip:5511999999999@sip.provider.com>"
        ],
        "Call-ID" => [
            "abc123def456@192.168.1.100"
        ],
        "CSeq" => [
            "1 CANCEL"  // Mesmo CSeq do INVITE
        ],
        "Max-Forwards" => [
            "70"
        ],
        "Contact" => [
            "<sip:1000@192.168.1.100:5062>"
        ],
        "User-Agent" => [
            "SPECHSHOP LIB"
        ]
    ]
]
```

---

### 6. REFER (Transferir Chamada)

Construído por: `transfer(string $to): bool`

```php
[
    "method" => "REFER",
    "methodForParser" => "REFER sip:1000@sip.provider.com SIP/2.0",
    "headers" => [
        "Via" => [
            "SIP/2.0/UDP 192.168.1.100:5060;branch=z9hG4bK-" . bin2hex(random_bytes(4))
        ],
        "From" => [
            "<sip:1000@sip.provider.com>;tag=abc123"
        ],
        "To" => [
            "<sip:5511999999999@sip.provider.com>"
        ],
        "Call-ID" => [
            "abc123def456@192.168.1.100"
        ],
        "CSeq" => [
            "3 REFER"
        ],
        "Contact" => [
            "<sip:1000@192.168.1.100>"
        ],
        "Refer-To" => [
            "sip:5511888888888@sip.provider.com"
        ],
        "Referred-By" => [
            "sip:1000@sip.provider.com"
        ],
        "Event" => [
            "refer"
        ],
        "Content-Length" => [
            "0"
        ]
    ]
]
```

**Headers Especiais:**
- `Refer-To`: SIP URI do destino da transferência
- `Referred-By`: Quem está iniciando a transferência
- `Event`: Deve ser "refer"

---

## Estrutura do Array SDP (Session Description Protocol)

O SDP define os parâmetros de mídia da chamada:

```php
"sdp" => [
    "v" => ["0"],                              // Versão SDP (sempre 0)
    
    "o" => [                                   // Originador (owner)
        "ssrc 0 0 IN IP4 192.168.1.100"
    ],
    
    "s" => ["SPECHSHOP LIB"],                  // Nome da sessão (session name)
    
    "c" => ["IN IP4 192.168.1.100"],          // Conexão (connection)
    
    "t" => ["0 0"],                            // Timing (sempre 0 0 para permanente)
    
    "m" => [                                   // Mídia (media)
        "audio 10000 RTP/AVP 0 18 101"
        //       ^port ^protocol  ^payloads
    ],
    
    "a" => [                                   // Atributos (attributes)
        "ssrc:1234567890 cname:user@host",    // SSRC e CNAME
        "rtpmap:0 PCMU/8000",                 // Mapa de tipos de payload
        "rtpmap:18 G729/8000",
        "fmtp:18 annexb=no",                  // Parâmetros de formato
        "rtpmap:101 telephone-event/8000",
        "fmtp:101 0-16",                      // DTMF de 0 a 16
        "ptime:20",                           // Tempo de pacote (ms)
        "sendrecv"                            // Direção (sendrecv, sendonly, recvonly)
    ]
]
```

### Campos SDP Explicados:

| Campo | Exemplo | Significado |
|-------|---------|-------------|
| `v` | `0` | Versão (sempre 0) |
| `o` | `ssrc 0 0 IN IP4 host` | Originador (versão sessão, versão, tipo IP, IP) |
| `s` | `SPECHSHOP LIB` | Nome da sessão |
| `c` | `IN IP4 192.168.1.100` | Conexão (tipo, versão IP, endereço) |
| `t` | `0 0` | Timing (start stop - 0 0 = permanente) |
| `m` | `audio 10000 RTP/AVP 0 18 101` | Mídia (tipo porta protocolo payloads) |
| `a=rtpmap` | `rtpmap:0 PCMU/8000` | Mapeamento de payload (ID nome/taxa) |
| `a=fmtp` | `fmtp:18 annexb=no` | Parâmetros de formato específicos |
| `a=ptime` | `ptime:20` | Tempo de pacote em ms |
| `a=sendrecv` | `sendrecv` | Direção de mídia |

---

## Fluxo de Construção de Arrays (Exemplos Práticos)

### Exemplo 1: Chamada Outbound Completa

```php
// 1. Construir INVITE com SDP
$modelInvite = $phone->modelInvite('5511999999999');
$phone->socket->sendto($host, $port, sip::renderSolution($modelInvite));

// 2. Receber 100 Trying (resposta de progresso - ignorar)
// 3. Receber 180 Ringing (callback onRinging)
// 4. Receber 200 OK com SDP resposta
$receive = sip::parse($packet);
// $receive contém:
//   ['method'] => 200
//   ['headers'] => [...headers...]
//   ['sdp'] => [...sdp remoto...]

// 5. Enviar ACK
$modelAck = $phone->ackModel($receive['headers']);
$phone->socket->sendto($host, $port, sip::renderSolution($modelAck));

// 6. Trocar mídia RTP...
// 7. Ao desligar: enviar BYE
$modelBye = renderMessages::generateBye($receive['headers']);
$phone->socket->sendto($host, $port, sip::renderSolution($modelBye));
```

### Exemplo 2: Autenticação Digest

```php
// 1. Enviar INVITE inicial
$modelInvite = $phone->modelInvite($to);
$phone->socket->sendto($host, $port, sip::renderSolution($modelInvite));

// 2. Receber 407 Proxy Authentication Required ou 401 Unauthorized
$receive = sip::parse($packet);
// $receive['headers']['Proxy-Authenticate'][0] = 
//   'Digest realm="provider.com", nonce="abc123", qop="auth"'

// 3. Extrair parâmetros de desafio
$nonce = value($receive['headers']['Proxy-Authenticate'][0], 'nonce="', '"');
$realm = value($receive['headers']['Proxy-Authenticate'][0], 'realm="', '"');
$qop = value($receive['headers']['Proxy-Authenticate'][0], 'qop="', '"');

// 4. Calcular response
$response = sip::generateResponseProxy(
    $username, $password, $realm, $nonce, 
    "sip:to@host", "INVITE", $qop
);

// 5. Incrementar CSeq e adicionar Authorization
$modelInvite['headers']['CSeq'] = [$csq + 1 . " INVITE"];
$modelInvite['headers']['Proxy-Authorization'] = [
    "Digest username=\"{$username}\", " .
    "realm=\"{$realm}\", " .
    "nonce=\"{$nonce}\", " .
    "uri=\"sip:{$to}@{$host}\", " .
    "response=\"{$response}\", " .
    "qop={$qop}"
];

// 6. Reenviar INVITE autenticado
$phone->socket->sendto($host, $port, sip::renderSolution($modelInvite));
```

---

## Função de Renderização: `sip::renderSolution()`

Converte o array estruturado em string HTTP-like pronta para envio:

```php
$model = [
    "method" => "INVITE",
    "methodForParser" => "INVITE sip:5511999999999@host SIP/2.0",
    "headers" => ["Via" => ["..."], ...],
    "sdp" => [...]
];

$rawMessage = sip::renderSolution($model);
// Resultado:
// "INVITE sip:5511999999999@host SIP/2.0\r\n
//  Via: ...\r\n
//  From: ...\r\n
//  ...\r\n
//  \r\n
//  v=0\r\n
//  o=...\r\n
//  ..."
```

---

## Regras Importantes para Construção de Arrays

### 1. **Headers Sempre São Arrays**
```php
// ❌ ERRADO
"From" => "<sip:user@host>"

// ✅ CORRETO
"From" => ["<sip:user@host>"]
```

### 2. **Call-ID Deve Ser Único**
```php
// Gerado uma vez no constructor
$this->callId = uniqid() . "@" . $this->localIp;

// Reutilizado em todas mensagens da mesma sessão
"Call-ID" => [$this->callId]
```

### 3. **CSeq Deve Incrementar**
```php
// INVITE = 1
$this->csq = 1;
"CSeq" => ["1 INVITE"]

// Próximo método = 2
$this->csq++;
"CSeq" => ["2 ACK"]

// Próximo = 3
$this->csq++;
"CSeq" => ["3 BYE"]
```

### 4. **Via com Branch Deve Ser Único**
```php
"Via" => [
    "SIP/2.0/UDP host:port;branch=z9hG4bK" . bin2hex(random_bytes(8))
]
```

### 5. **Tags em From/To**
```php
// From SEMPRE tem tag
"From" => ["<sip:user@host>;tag=" . bin2hex(random_bytes(8))]

// To em INVITE não tem tag (adicionado pelo server na resposta)
"To" => ["<sip:dest@host>"]

// To em respostas/acks tem tag (do server)
"To" => ["<sip:dest@host>;tag=server-generated"]
```

### 6. **SDP Presente Apenas em INVITE/200 OK**
```php
// INVITE deve ter SDP
"Content-Type" => ["application/sdp"]
"sdp" => [...]

// BYE não deve ter SDP
"Content-Length" => ["0"]
// sem "sdp" key
```

---

## Parsing vs Rendering

### Parsing (String → Array)
```php
$rawMessage = "INVITE sip:... SIP/2.0\r\nVia: ...\r\n...";
$parsed = sip::parse($rawMessage);
// Resultado: array estruturado
```

### Rendering (Array → String)
```php
$model = ["method" => "INVITE", "headers" => [...], ...];
$rawMessage = sip::renderSolution($model);
// Resultado: string pronta para envio UDP
```

---

## Resumo das Transformações

```
┌─────────────────────────────────┐
│  Usuário cria array modelo      │
│  (modelInvite, modelRegister)   │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  sip::renderSolution()          │
│  Converte array em string HTTP  │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  Socket UDP envia string        │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  Servidor SIP recebe string     │
│  e responde com nova string     │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  sip::parse()                   │
│  Converte string em array       │
└────────────┬────────────────────┘
             │
             ▼
┌─────────────────────────────────┐
│  Usuário processa array         │
│  (checkAuth, renderMessages)    │
└─────────────────────────────────┘
```

---

## Referência Rápida de Arrays

| Método | Construtor | Responsabilidade |
|--------|-----------|------------------|
| INVITE | `modelInvite()` | Iniciar chamada com SDP |
| REGISTER | `modelRegister()` | Registrar no servidor |
| ACK | `ackModel()` | Confirmar 200 OK |
| BYE | `renderMessages::generateBye()` | Finalizar chamada |
| CANCEL | `getModelCancel()` | Cancelar chamada |
| REFER | `transfer()` | Transferir chamada |
| OPTIONS | `modelOptions()` | Ping/keep-alive |
| Respostas | `renderMessages::baseResponse()` | Responder a requisição |

---

## Conclusão

Os arrays de sinalização são o coração da biblioteca. Dominar sua estrutura é essencial para:
- Entender o fluxo de chamadas
- Debugar problemas de sinalização
- Estender a biblioteca com novos tipos de mensagem
- Integrar com proxies SIP customizados

Cada array segue rigorosamente o padrão RFC 3261 (SIP) e RFC 4566 (SDP).

