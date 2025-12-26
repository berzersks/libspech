# Git Commit Message Instructions (Strict)

Este arquivo define regras **obrigatórias** para geração de mensagens de commit.
Ele deve ser seguido por humanos e por qualquer ferramenta de IA (ex: Copilot).

---

## Objetivo

A mensagem de commit deve documentar tecnicamente a mudança realizada, permitindo que,
meses depois, qualquer desenvolvedor consiga entender:

- O que mudou
- Por que mudou (causa real)
- Onde o problema ocorria (arquivo, componente ou fluxo)
- Quais impactos ou riscos existem

Commits vagos geram dívida técnica.
Commits claros evitam investigação futura.

---

## Idioma (Obrigatório)

Todas as mensagens de commit **DEVEM ser escritas em Português (pt-BR)**.

### Exceções permitidas
- Nomes de arquivos
- Nomes de classes, funções ou métodos
- Termos técnicos consolidados (RTP, SIP, worker, buffer, codec)

### Estilo do idioma
- Técnico e objetivo
- Frases claras e diretas
- Sem gírias
- Sem emojis
- Sem traduções literais do inglês

---

## Estrutura Obrigatória do Commit

### 1. Título (primeira linha)
- Máximo de 72 caracteres
- Frase no imperativo
- Deve descrever a mudança principal

Formato recomendado:
```
Verbo + objeto + contexto técnico
```

Exemplos:
- Corrige inicialização incompleta da configuração SIP
- Refatora fluxo de recepção de áudio no MediaChannel

---

### 2. Corpo do commit (Obrigatório)

O corpo do commit **DEVE explicar a causa da mudança**.

É obrigatório responder explicitamente:
- Qual era o problema
- Em qual ponto ele ocorria
- Qual comportamento incorreto era observado

Frases vagas como “melhorias”, “ajustes” ou “otimizações” **não são aceitas**
sem explicação causal.

Exemplos aceitáveis:
- “O problema ocorria quando o arquivo .env não existia, fazendo com que…”
- “A lógica anterior permitia que o trunkController operasse com valores implícitos…”
- “Isso resultava em perda inicial de pacotes de áudio…”

---

### 3. Mudanças relevantes (Obrigatório)

Liste apenas mudanças que alteram comportamento real do sistema.

Formato obrigatório:
- `- Adicionado …`
- `- Alterado …`
- `- Corrigido …`
- `- Removido …`
- `- Refatorado …`

Sempre que possível, indique:
- Arquivo
- Classe
- Componente

---

### 4. Impactos, riscos ou limitações (Obrigatório)

Se houver **qualquer possibilidade** de impacto, ela deve ser descrita.

Inclui, mas não se limita a:
- Mudança de timing
- Impacto de performance
- Alteração de comportamento sob carga
- Dependência de configuração
- Necessidade de monitoramento pós-deploy

Formato recomendado:
```
⚠️ Possível impacto: …
⚠️ Limitação conhecida: …
```

Se **nenhum risco for conhecido**, declarar explicitamente:
```
Nenhum efeito colateral conhecido até o momento.
```

Nunca assumir “seguro por padrão”.

---

### 5. Localização do problema (Obrigatório quando aplicável)

Sempre que possível, indique **onde o problema existia**:
- Arquivo
- Classe
- Método
- Fluxo (ex: inicialização, recepção RTP, timeout)

Exemplo:
- `Problema ocorria em trunkController durante a inicialização SIP`
- `Falha localizada no fluxo de recepção RTP do MediaChannel`

---

## Uso por IA (Copilot / Chat)

Ferramentas de IA **DEVEM**:
- Declarar causa, não apenas mudança
- Indicar onde o problema ocorria
- Evitar termos genéricos
- Declarar incertezas quando não for possível inferir

Se a causa não puder ser determinada, isso deve ser dito explicitamente.

---

## Regra Final (Não Negociável)

Se o commit não explica **o motivo e o local do problema**, ele é considerado inválido.

Commit bom documenta decisão técnica.
Commit ruim vira arqueologia de bug.