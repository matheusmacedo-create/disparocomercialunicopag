# ÚnicoPag Disparos

Plataforma de disparos de WhatsApp da ÚnicoPag: **recuperação** de sellers inativos, **ativação** de
sellers novos e **ofertas** a bases compartilhadas com a UnicoDrop, com bases de contatos, segmentos,
campanhas aprovadas em quatro olhos, réguas de nutrição e as proteções que o incidente de 26/10/2026
(número comercial em RED depois de 650 envios frios) exigiu: opt-out para sempre, teto diário com
aquecimento, janela de horário, tranches com disjuntor de engajamento e trilha de tudo.

Fala com a **Kapso** (WhatsApp oficial, histórico, webhook) e com a **Meta Cloud API** direta. Mesmo desenho
do KYC (`unico-pag/unicopag-kyc`) e do MED (`autorespondermed2.0`): Laravel 12, Filament 4 em `/admin`,
MySQL/MariaDB em produção, SQLite nos testes, fila em banco, Forge.

A memória do sistema (como ele se comporta e por quê) está em **`doc/DISPARO_KNOWLEDGE_BASE.md`**. Leia
antes de mexer.

## Rodar localmente

```bash
composer setup            # install, .env, key, migrate, npm install, build
php artisan make:filament-user   # o primeiro usuário vira Super Admin
composer run dev          # server + fila + logs + vite
```

No `.env`: `DISPARO_HASH_PEPPER` (gere com `openssl rand -hex 32`; sem ele nenhum hash sai) e, para
falar com a Kapso, `KAPSO_API_KEY`. `DISPARO_ENVIO_HABILITADO=false` (padrão) mantém tudo em simulação:
a campanha roda inteira sem mandar nada.

Testes: `composer test` (PHPUnit; Kapso e Meta sempre por `Http::fake`). Estilo: `./vendor/bin/pint`.

## Fluxo de uso

1. **Configuração → Remetentes**: cadastre o número (provider, `phone_number_id`, categorias permitidas
   MARKETING/UTILITY, teto, ritmo, janela), registre o webhook e sincronize número e templates.
2. **Importações**: importe o **histórico da Kapso** do número (quem já recebeu o quê, quem respondeu,
   quem pediu para parar) e as bases (CSV da UnicoDrop, sellers inativos do unico-api).
3. **Contatos / Bases / Segmentos**: organize quem recebe o quê; bloqueios e pedidos para parar valem
   para sempre.
4. **Disparos → Campanhas**: template aprovado, variáveis mapeadas, público, prévia com motivos de
   exclusão e custo, aprovação por outra pessoa (ou auto-aprovação com espera e teto), execução em
   tranches dentro do teto e da janela.
5. **Disparos → Réguas**: sequências de passos (template, aguardar, condição, texto na resposta),
   aprovadas como as campanhas.
6. **Conversas**: respostas dos clientes para tratar (janela de 24 h, pedidos LGPD com prazo).

## Comandos

| Comando | O que faz |
|---|---|
| `disparo:executar-campanhas` | uma rodada das campanhas em andamento (agendado a cada minuto) |
| `disparo:processar-reguas` | passos vencidos das réguas (a cada 10 min); `--segmentos` reavalia gatilhos |
| `disparo:sincronizar-remetentes` | qualidade, tier e saúde dos números (de hora em hora; `--rapido` a cada 10 min com campanha rodando) |
| `disparo:sincronizar-templates` | cache dos templates da Meta |
| `disparo:importar-historico-kapso {sender}` | histórico de conversas, mensagens e broadcasts da Kapso |
| `disparo:importar-csv`, `disparo:importar-sellers-inativos` | bases |
| `disparo:registrar-webhook {sender}` | gera o segredo e cadastra o webhook na Kapso |
| `disparo:diagnostico` | cron, worker, interruptor, pepper, remetentes, webhooks |
| `disparo:podar` | retenção (LGPD) |

Deploy: `doc/DEPLOY_FORGE.md` e `deploy/forge-deploy.sh`.

## `mcp/`

A CLI/MCP original em TypeScript (direto na Cloud API da Meta). Continua útil para listar templates,
consultar o número e simular; o **envio real fica desligado** (`MCP_ENVIO_REAL=true` só para emergência),
porque ela não vê supressões, teto, janela nem aprovação. Caminho natural: cliente da `/api/v1`.
