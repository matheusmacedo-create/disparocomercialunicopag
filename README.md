# Disparo Comercial Unicopag — WhatsApp via MCP (Meta Cloud API)

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
claude mcp add unicopag-whatsapp -- node /caminho/para/dist/mcp-server.js
```

**Claude Desktop** — em `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "unicopag-whatsapp": {
      "command": "node",
      "args": ["/caminho/absoluto/disparocomercialunicopag/dist/mcp-server.js"],
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
