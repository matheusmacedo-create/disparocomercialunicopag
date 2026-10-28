# Disparo Comercial Unicopag — WhatsApp via MCP (Meta Cloud API)

> **Desde 28/10/2026 o canal de envio é a plataforma Laravel na raiz deste repositório** (painel
> `/admin`): ela aplica supressões (opt-out), teto diário, aquecimento, janela, aprovação em quatro olhos
> e grava a trilha. Este MCP/CLI fala direto com a Meta e **não vê nada disso**, então o envio real aqui
> fica **desligado** (`MCP_ENVIO_REAL=true` só para emergência conhecida). Listar templates, consultar o
> número e simular continuam funcionando. O caminho natural é reescrever as ferramentas como cliente da
> API da plataforma (`/api/v1`, chave `API_KEY`). O arquivo `mcp.json.example` registra o servidor no
> Claude Code se você quiser usá-lo.

Servidor **MCP** e **CLI** para disparos comerciais pelo número de WhatsApp Business da Unicopag,
usando a **WhatsApp Business Cloud API** oficial da Meta (Graph API).

Com o MCP conectado ao Claude (Claude Code, Claude Desktop, etc.) você pode pedir:
> "Lista meus templates aprovados", "Manda o template X de teste pro meu número",
> "Dispara o template prospeccao_maquininha para data/contatos.csv usando o first_name".

## Conta configurada

| Item | Valor |
|---|---|
| Número | +55 21 92367-4860 — *Matheus Macedo -ÚnicoPag Checkout\|Gateway* |
| Phone Number ID | `1161905150334981` |
| WABA ID | `1278445183736183` |
| Business ID | `1127366268551701` |

Esses IDs já são o padrão no código. **Falta apenas o `WHATSAPP_TOKEN`** (nunca faça commit dele).

## 1. Pré-requisitos na Meta

1. **Business Manager** da Unicopag verificado.
2. Em [developers.facebook.com](https://developers.facebook.com) → seu App → produto **WhatsApp**:
   - Confirme que o App está vinculado à WABA `1278445183736183`.
3. Crie um **token permanente**: Business Manager → Configurações → Usuários do sistema →
   gere um token com `whatsapp_business_messaging` e `whatsapp_business_management`.
4. Crie e aprove os **templates** (categoria *Marketing* para prospecção) no WhatsApp Manager.
   Mensagem que *você* inicia só pode ser enviada como template aprovado.

### Registrar o número (status DISCONNECTED)

Se `whatsapp_info_numero` mostrar `status: DISCONNECTED`, o número ainda não está ativo na Cloud API:

```bash
npm run registrar -- status
npm run registrar -- solicitar SMS        # código chega por SMS no +55 21 92367-4860
npm run registrar -- verificar 123456     # código recebido
npm run registrar -- registrar 654321     # PIN de 6 dígitos (guarde: é a verificação em 2 etapas)
```

> Se o número estiver ativo no app WhatsApp/WhatsApp Business do celular, ele será desconectado do app
> ao registrar na Cloud API. Apague a conta no app antes, ou use outro número.

## 2. Instalação

```bash
npm install
cp .env.example .env   # preencha token, phone number id e WABA id
npm run build
npm test
```

## 3. Conectar como MCP

**Claude Code** — o `.mcp.json` na raiz já registra o servidor ao abrir o projeto
(rode `npm run build` antes). Ou manualmente:

```bash
claude mcp add unicopag-whatsapp -- node /caminho/para/mcp/dist/mcp-server.js
```

**Claude Desktop** — em `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "unicopag-whatsapp": {
      "command": "node",
      "args": ["/caminho/absoluto/disparocomercialunicopag/mcp/dist/mcp-server.js"],
      "env": {
        "WHATSAPP_TOKEN": "EAAG..."
      }
    }
  }
}
```

### Ferramentas expostas

| Ferramenta | O que faz |
|---|---|
| `whatsapp_info_numero` | Nome verificado, qualidade (GREEN/YELLOW/RED) e limite diário |
| `whatsapp_registrar_numero` | Solicita código, verifica e registra o número na Cloud API |
| `whatsapp_listar_templates` | Templates da WABA, com filtro por status |
| `whatsapp_enviar_template` | Envia um template para 1 número (teste) |
| `whatsapp_enviar_texto` | Texto livre (só entrega dentro da janela de 24h) |
| `whatsapp_disparo_em_massa` | Disparo em massa a partir de CSV/lista (roda em 2º plano, **simulação por padrão**) |
| `whatsapp_status_disparo` | Progresso do disparo |
| `whatsapp_cancelar_disparo` | Interrompe um disparo em andamento |

## 4. Disparo pela linha de comando

O CSV é o mesmo formato do ManyChat (`phone,first_name`); aceita `,` ou `;` e detecta a
coluna de telefone (`phone`, `telefone`, `whatsapp`, `celular`...).

```bash
# Simulação (não envia nada, gera relatório em data/)
npm run disparo -- --csv data/contatos.csv --template prospeccao_maquininha --var "{{first_name|tudo bem}}"

# Usando um arquivo de campanha
npm run disparo -- --csv data/contatos.csv --config campanha.exemplo.json

# Envio real
npm run disparo -- --csv data/contatos.csv --config campanha.exemplo.json --enviar
```

### Variáveis do template

- `{{coluna}}` → valor da coluna do CSV para aquele contato.
- `{{coluna|padrão}}` → usa o padrão se a coluna estiver vazia (a Meta rejeita variável vazia).
- Templates com variáveis nomeadas: use `variaveisNomeadas` (`{"nome": "{{first_name}}"}`).
- Cabeçalho: `headerImagemUrl`, `headerDocumentoUrl`, `headerVideoUrl` ou `headerTexto`.
- Botão de URL dinâmico: `botaoUrlSufixo`.

## 5. Proteções embutidas

- **Simulação por padrão** — envio real só com `--enviar` / `dry_run: false`.
- Normalização de telefone brasileiro (DDI 55), remoção de **duplicados** e **inválidos**.
- **Opt-out**: números em `data/optout.txt` (um por linha) nunca recebem mensagem.
- Controle de velocidade (`DISPARO_MSG_POR_SEGUNDO`) e teto por execução (`DISPARO_LIMITE_POR_EXECUCAO`).
- Nova tentativa com backoff em limites de taxa (429, 130429, 131056).
- **Para tudo** em erros de conta/token ou de spam (190, 131031, 131048, 368) para não queimar o número.
- CLI aborta o envio real se a qualidade do número estiver **RED**.
- Relatório CSV por execução em `data/resultado-*.csv` (status, motivo, message_id).

> Boas práticas: dispare só para quem deu consentimento (LGPD e política da Meta),
> ofereça opção de sair na mensagem e comece com lotes pequenos — o limite diário
> do número (tier) sobe conforme a qualidade se mantém boa.
