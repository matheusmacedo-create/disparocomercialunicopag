# disparocomercialunicopag

Plataforma de disparos de WhatsApp da ÚnicoPag: recuperação e ativação de sellers e ofertas a bases
compartilhadas com a UnicoDrop, via Kapso (WhatsApp oficial) e Meta Cloud API. Mesmo desenho do KYC
(`unico-pag/unicopag-kyc`) e do MED (`autorespondermed2.0`): painel Filament em `/admin`, fila em banco,
trilha de eventos, alertas no Discord, deploy no Forge. Aqui o "onboarding" é a campanha/régua e o
"documento" é o template.

## Stack
- Laravel 12, PHP ^8.3, Filament 4 (painel em `/admin`), spatie/laravel-permission.
- Produção: MySQL/MariaDB + fila em banco. Testes: SQLite isolado por processo (`tests/bootstrap.php`).
- `mcp/`: a CLI/MCP original em TypeScript (direto na Meta). Envio real desligado por padrão
  (`MCP_ENVIO_REAL=true` só para emergência). Não é canal de envio.

## Comandos
- Setup: `composer setup`. Dev: `composer run dev`. Testes: `composer test` (PHPUnit, sem Pest). Estilo:
  `./vendor/bin/pint`.
- Usuário do painel: `php artisan make:filament-user` (o primeiro vira Super Admin).
- Rotinas (precisam do cron `schedule:run` e do worker `queue:work`): `executar-campanhas` (a cada minuto),
  `processar-reguas` (10 min; `--segmentos` 09:30), `sincronizar-remetentes` (hora; `--rapido` 10 min),
  `sincronizar-templates` (6 h), `importar-historico-kapso --todos` (03:00; `--incremental` 15 min com campanha
  rodando), `podar` (04:00). Ver README e `routes/console.php`.
  `php artisan disparo:diagnostico` confere o ambiente de verdade (batimentos, interruptor, pepper,
  webhooks na Kapso).

## Estrutura que importa
- `app/Services/WhatsApp/` - `KapsoClient` e `MetaCloudClient` (mesma Graph API, autenticação diferente),
  `WhatsAppException` com as recusas traduzidas e as classes do disjuntor.
- `app/Services/Envio/` - `Guardas` (elegibilidade), `Despachante` (um envio, com trilha),
  `ExecutorDeCampanha` (rodada: ritmo, teto, tranches, disjuntores), `DisjuntorDeEngajamento`,
  `TravaDoRemetente` (uma trava por número para todo envio), `MontadorDeComponentes`.
- `app/Services/Campanhas/` - `FluxoDeCampanha` (as transições, com autorização no servidor),
  `MontadorDePublico`, `ValidadorDeCampanha`. `app/Services/Reguas/` - fluxo e processador das réguas.
- `app/Services/Webhooks/` - `ProcessadorDeEventos` (Kapso e Meta no mesmo formato),
  `ClassificadorDeResposta` (parar / agora não / opt-in / pedido LGPD; portado do KYC).
- `app/Services/Importacao/` - CSV, histórico da Kapso, sellers inativos, retenção.
- `app/Support/` - `Telefone` (E.164, nono dígito, fuso por DDD, HMAC), `Mask` (PII e scrubber),
  `Permissions`, `Variaveis`.
- `app/Filament/` - painel. `routes/api.php` - webhooks e `/api/v1` (X-API-Key).

## Regras que não se relaxam
- **Simulação por padrão**: `DISPARO_ENVIO_HABILITADO=false` roda tudo sem mandar nada.
- **Opt-out é para sempre e vale para o número**: `Suppression::bloqueia()` é a única pergunta do envio;
  escopo `tudo` nunca tem remetente. Liberar só com `release suppressions`, justificativa, nunca denúncia.
- **Quatro olhos**: campanha e régua só saem aprovadas por outra pessoa (ou auto-aprovação com espera e
  teto). Transições só pelos fluxos; `status` fora do `$fillable`.
- **Todo envio de template passa por `Despachante` + `TravaDoRemetente`** e pelas guardas.
- **Nada de PII em log, Discord, payload ou mensagem de erro** (`Mask::scrub`, `Telefone::mascarar`).
  Texto do cliente só com `view conversations`; documento/e-mail/atributos/variáveis só com `view pii`.
- **Hash de telefone é HMAC com `DISPARO_HASH_PEPPER`**: nunca trocar depois do primeiro import.
- Toda ação nova do painel precisa de `->visible(fn () => Permissions::allows(...))` e checagem no
  servidor; o Filament recusa chamar ação escondida.
- Todo TIMESTAMP novo em migration é nullable (MariaDB 10.6). Colunas com tamanho: `mb_substr`.
- Fuso: a app roda em `America/Sao_Paulo`. Agenda com `timezone()` antes do horário e
  `withoutOverlapping(N)` sempre com minutos.

## Armadilhas
- O número comercial é compartilhado com o KYC (avisos de primeira venda): o teto e a conversa aberta
  enxergam o que sai por fora só pelo webhook (status de wamid desconhecido vira linha `origem=webhook`)
  e pela importação incremental do histórico. Decisão pendente de um dono só por número (KB 2 e 13).
- `kapso(default)` nos campos das listas da Kapso: `kapso()` vazio OMITE os campos.
- 131050 = parou marketing (supressão de marketing do remetente); 131049 = carência de 7 dias, nunca
  supressão; 131026 = sem WhatsApp por 90 dias; 131048/368/131031 = bloqueio por qualidade sem sonda.
- Template reclassificado pela Meta depois da aprovação devolve a campanha para aprovação.

## Base de conhecimento (ler e manter)
`doc/DISPARO_KNOWLEDGE_BASE.md` registra como o sistema se comporta, as decisões e o que está em aberto.
Leia antes de mexer no fluxo; atualize quando descobrir algo não óbvio (mesma regra do KYC e do MED).
