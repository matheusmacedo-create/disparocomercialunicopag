# Base de conhecimento - Disparo Comercial ÚnicoPag

Memória do sistema: como a plataforma de disparos **se comporta**, as decisões tomadas e o que ainda está
em aberto. Mesma regra do KYC (`unico-pag/unicopag-kyc`, `doc/KYC_KNOWLEDGE_BASE.md`) e do MED: leia
antes de mexer no fluxo; atualize quando descobrir algo não óbvio; o que precisa ser feito vai para card,
o que o sistema faz fica aqui.

Marcações: `[código]` conferido no código · `[prod]` medido em produção · `[decisão]` regra decidida pela
operação · `[inferido]` dedução ainda não confirmada.

## 1. O problema que a plataforma resolve

A ÚnicoPag dispara WhatsApp para três públicos, com três intenções diferentes:

| Público | De onde vem | O que se quer | Categoria Meta |
|---|---|---|---|
| **Sellers inativos** da ÚnicoPag (conta aprovada que parou de vender, ou nunca vendeu) | unico-api (contas), KYC (fichas aprovadas), CSV do comercial | **recuperar**: voltar a processar | UTILITY quando é aviso sobre a conta; MARKETING quando é oferta |
| **Sellers novos** (aprovados há pouco, configurando) | KYC (régua de ativação já existe lá) | **ativar**: primeira venda | UTILITY (o KYC já faz; aqui só o que o KYC não cobre) |
| **Bases compartilhadas com a UnicoDrop** (lojistas, leads de dropshipping) | CSVs da UnicoDrop, listas do painel da Kapso | **ofertar**: maquininha, checkout, gateway | MARKETING |

Até 28/10/2026 isso era feito por: (a) listas montadas à mão no painel da Kapso (número comercial),
(b) a CLI/MCP em TypeScript deste repositório (hoje em `mcp/`, direto na Cloud API da Meta, sem base de
contatos nem histórico), (c) a régua do KYC (só o funil de cadastro e ativação).

**O incidente que define as regras daqui** `[prod 26/10/2026, KB do KYC 8.23]`: o número comercial
(phone `1475615155627193`, WABA `1753802449254685`) caiu para qualidade **RED** depois de **650 envios
frios num dia**, com bloqueios e denúncias. A Meta mede a qualidade pelas denúncias e bloqueios dos últimos
7 dias; o que recupera é parar o frio e mandar mensagem que o cliente espera. Esse número virou "número de
avisos" do KYC. Logo: **a plataforma existe para disparar menos e melhor** - base organizada, opt-out
respeitado para sempre, teto diário, aquecimento, janela de horário, disjuntor por recusa da conta, e
trilha de tudo.

## 2. Decisões de produto `[decisão]`

1. **Simulação por padrão.** `DISPARO_ENVIO_HABILITADO=false` no ambiente faz tudo rodar como prévia
   (campanha "conclui" sem mandar nada, com o relatório do que sairia). Só liga quem administra o servidor.
   Herdado da CLI original ("envio real só com `--enviar`").
2. **Quatro olhos.** Campanha só sai depois de **prévia** (simulação com contagem e motivos de exclusão) e
   **aprovação** por quem tem `approve campaigns`. Quem criou não aprova a própria campanha (exceto Super
   Admin, que é quem opera sozinho hoje).
3. **Opt-out é para sempre e vale para o número**, não para a base: "pare", "sair da lista", o botão
   "Parar promoções" da Meta, "Denunciar" num template, ou o pedido coloquial ("não me manda mais nada")
   bloqueiam o telefone em todos os remetentes. Fica em `suppressions` (hash do telefone, para sobreviver à
   anonimização) e em `contacts.status = optout`. O classificador é o do KYC (regras estruturais, não
   regex única): ver seção 6.
4. **Preferência de marketing da Meta** (`131050` no envio ou `stopped` nas preferências da Kapso) bloqueia
   só template **MARKETING** naquele remetente; UTILITY (aviso de conta) continua permitido, como a Meta
   prevê. Pedido textual do cliente bloqueia tudo.
5. **Um remetente = um número = uma política**: categorias permitidas (o número do KYC só UTILITY; o
   comercial MARKETING e UTILITY), teto diário, mensagens por minuto, janela de horário, aquecimento. A
   plataforma nunca manda pelo número do KYC - ele tem a régua dele no repositório do KYC.
6. **Kapso é o provedor principal** (histórico, webhook assinado, preferências de marketing, broadcasts).
   A Cloud API direta da Meta (token permanente, como a CLI original) continua suportada como segundo
   provedor para número que não esteja na Kapso. Os dois falam a mesma Graph API; só a autenticação muda.
7. **Histórico vem da Kapso.** O operador pediu (28/10/2026): "na Kapso possui todo o histórico". A
   importação lê conversas, mensagens, contatos e broadcasts de cada número e reconstrói: quem já recebeu
   o quê, quem respondeu, quem pediu para parar, quem não tem WhatsApp. Antes de qualquer campanha para
   uma base velha, importa-se o histórico do número.
8. **Conversa aberta não recebe outro template** por 7 dias (`CONVERSA_ABERTA_DIAS`), em qualquer
   campanha do mesmo remetente - regra do KYC que vale aqui. Campanha pode marcar "ignorar conversa aberta"
   só para UTILITY.
9. **Frequência**: no máximo 2 templates por contato por remetente a cada 30 dias
   (`DISPARO_FREQUENCIA_MAX_30D`). Quem recebeu `131026` (sem WhatsApp) fica 90 dias fora.
10. **Janela**: 08:00-20:00 de Brasília por padrão (o KYC usa 08-21; oferta mais cedo). Fora dela a fila
    espera, não falha.
11. **Aquecimento**: remetente novo ou que voltou de RED/YELLOW começa com teto 50/dia, multiplicado por
    1,5 a cada dia com qualidade GREEN até o teto configurado. Qualidade RED trava o remetente; YELLOW corta
    o teto pela metade.
12. **Disjuntor**: recusa que vale para a conta inteira (pagamento, bloqueio, token, saldo da Kapso) para a
    campanha no primeiro contato e pausa o remetente com o motivo; a rodada seguinte manda **1** como sonda.
    Erro transitório (429, 5xx, timeout) espera e tenta de novo; três seguidos pausam a campanha.
13. **LGPD**: CPF/CNPJ criptografado no banco e mascarado no painel; conteúdo das conversas visível só com
    `view pii`; "Anonimizar" apaga nome, e-mail, documento e conteúdo, mantém a supressão pelo hash do
    telefone. Base compartilhada com a UnicoDrop exige `consentimento_origem` preenchido na importação
    (campo obrigatório quando a base está marcada como "exige consentimento").
14. **Nada de PII no log nem no Discord**: telefone mascarado (`+55 21 9****-5432`), sem nome, sem texto.

## 3. Arquitetura em um minuto `[código]`

Laravel 12 + Filament 4 (painel em `/admin`), MySQL/MariaDB em produção (Forge, como o KYC e o MED),
SQLite nos testes, fila em banco, agendador por `schedule:run`.

```
Remetente (sender) ──► WhatsAppClient (Kapso | Meta Cloud) ──► Meta
     │                        ▲
     │ webhook assinado        │ envia template / texto
     ▼                        │
ProcessadorDeEventos ──► whatsapp_messages (trilha) ──► campaign_recipients / journey_enrollments
     │
     └─► ClassificadorDeResposta ──► suppressions + contacts.status
```

- `app/Models` - Sender, Contact, Tag, ContactList, Segment, ContactImport, MessageTemplate, Campaign,
  CampaignRecipient, CampaignEvent, WhatsappMessage, Suppression, Journey, JourneyStep, JourneyEnrollment,
  SenderQualitySnapshot, WebhookCall, User.
- `app/Services/WhatsApp` - `WhatsAppClient` (contrato), `KapsoClient`, `MetaCloudClient`,
  `WhatsAppClientFactory`, `WhatsAppException` (recusas traduzidas; `afetaTodos()`, `transitoria()`).
- `app/Services/Envio` - `Guardas` (elegibilidade de contato e remetente), `MontadorDeComponentes`
  (variáveis `{{coluna|padrão}}` → componentes da Cloud API, a partir da definição do template),
  `Despachante` (um envio, com registro), `ExecutorDeCampanha` (lote com ritmo, trava e disjuntor).
- `app/Services/Campanhas` - `MontadorDePublico` (listas + segmento - exclusões → destinatários com motivo).
- `app/Services/Reguas` - `ProcessadorDeReguas` (passos, esperas, condições, saída ao responder).
- `app/Services/Webhooks` - `ProcessadorDeEventos` (um formato interno para Kapso e Meta),
  `ClassificadorDeResposta`.
- `app/Services/Importacao` - `ImportadorCsv`, `ImportadorKapso` (histórico), `ImportadorSellersInativos`
  (unico-api, conexão só de leitura opcional), `ImportadorOptOut`.
- `app/Services/Segmentos/ConsultaDeSegmento` - regras JSON → query.
- `app/Support` - `Telefone` (E.164 brasileiro, máscara, formas com/sem nono dígito), `Mask`,
  `Permissions`, `Variaveis`.
- `app/Console/Commands` - `disparo:*` (seção 8).
- `mcp/` - a CLI/MCP original em TypeScript (direto na Cloud API). Continua funcionando sozinha; não lê o
  banco da plataforma. Caminho natural: virar cliente da API da plataforma.

## 4. Modelo de dados `[código]`

### 4.1 Remetentes (`senders`)
Um por número. `provider` (`kapso` | `meta`), `phone_number_id` (único), `waba_id`, `finalidade`
(`comercial` | `avisos` | `kyc` | `teste`), `api_key` e `webhook_secret` criptografados (vazio = usa o
`.env`: `KAPSO_API_KEY` / `META_WHATSAPP_TOKEN`), `categorias_permitidas` (JSON, ex.: `["MARKETING","UTILITY"]`),
`teto_diario`, `msgs_por_minuto`, `janela_inicio`/`janela_fim`, `fuso`, `aquecimento_inicio`,
`aquecimento_teto_inicial`, `aquecimento_fator`, `pausado_em`/`pausado_motivo`, `qualidade`
(`GREEN|YELLOW|RED|UNKNOWN`), `limite_tier`, `status_meta`, `saude` (JSON da Kapso), `sincronizado_em`,
`templates_sincronizados_em`, `historico_importado_ate`, `ativo`.

### 4.2 Contatos (`contacts`)
`telefone` E.164 com `+` (único; mesma normalização do KYC: DDD 11-99 + fixo 8 dígitos começando em 2-5 ou
celular 9 dígitos começando em 9; o que não reconhece é recusado), `nome`, `primeiro_nome`, `email`,
`documento` (criptografado) + `documento_hash` (sha256, para busca e dedupe), `empresa`, `origem`
(`unicodrop` | `unicopag` | `csv` | `kapso` | `unicoapi` | `manual`), `tipo` (`seller_inativo` |
`seller_ativo` | `lead` | `cliente` | `outro`), `account_id` (unico-api), `atributos` (JSON: as colunas
extras do CSV, disponíveis como variáveis), `status` (`ativo` | `optout` | `invalido` | `anonimizado`),
`optout_em`/`optout_motivo`/`optout_origem`, `sem_whatsapp_em`, `consentimento_origem`/`consentimento_em`,
contadores (`envios_total`, `entregues_total`, `lidos_total`, `respostas_total`, `falhas_total`),
`ultimo_envio_em`, `ultimo_template_em`, `ultima_resposta_em`, `kapso_contact_id`, `observacoes`.
Relações: `tags` (N:N), `lists` (N:N com `adicionado_em` e `import_id`), `messages`, `campaignRecipients`,
`journeyEnrollments`.

### 4.3 Bases e segmentos
- `contact_lists`: `nome`, `slug`, `descricao`, `dono` (`unicopag` | `unicodrop` | `compartilhada`),
  `exige_consentimento`, `ativa`. É a "base": Sellers inativos 90d, Leads UnicoDrop out/2026...
- `tags`: `nome` único, `cor`.
- `segments`: `nome`, `regras` (JSON): `listas`, `excluir_listas`, `tags`, `excluir_tags`, `origens`,
  `tipos`, `status` (padrão `["ativo"]`), `sem_envio_ha_dias`, `sem_resposta_ha_dias`,
  `respondeu_ha_dias`, `criado_apos`, `atributos` (`[{campo, operador, valor}]`, operadores `=`, `!=`,
  `contem`, `vazio`, `nao_vazio`). `ConsultaDeSegmento::query($segment)` é a única tradução para SQL; o
  painel mostra a contagem ao vivo.

### 4.4 Importações (`contact_imports`)
`tipo` (`csv` | `kapso` | `unicoapi` | `optout`), `arquivo`, `nome_original`, `mapeamento` (JSON coluna →
campo), `list_id`, `origem`, `tipo_contato`, `tags`, `consentimento_origem`, `status`, contagens
(`total_linhas`, `criados`, `atualizados`, `ignorados`, `invalidos`, `duplicados`, `optouts`), `erros`
(primeiros 100), `user_id`, `iniciado_em`, `concluido_em`. Roda em job de fila; CSV aceita `,` ou `;`,
detecta a coluna de telefone (`phone`, `telefone`, `whatsapp`, `celular`, `numero`, `fone`) e o BOM.
Telefone já existente **atualiza** (nome vazio não apaga nome preenchido; atributos fazem merge) e
entra na base; contato `optout` ou `anonimizado` entra na base mas segue bloqueado.

### 4.5 Templates (`message_templates`)
Cache por remetente do que a Meta devolve: `meta_id`, `nome`, `idioma`, `categoria`, `status`,
`parameter_format`, `componentes` (JSON), `sincronizado_em`. Único por (remetente, nome, idioma). O envio
**sempre** reconfere a definição antes de montar os componentes (a Meta reclassifica template depois de
aprovado - KB do KYC 8.23).

### 4.6 Campanhas (`campaigns`, `campaign_recipients`, `campaign_events`)
Campanha: `nome`, `sender_id`, `template_nome`, `template_idioma`, `variaveis` (JSON: `corpo` lista
posicional ou mapa nomeado, `cabecalho_texto`, `cabecalho_midia_url`, `cabecalho_midia_tipo`,
`botao_url_sufixo`), `publico` (JSON: `listas`, `segment_id`, `excluir_listas`, `excluir_tags`),
`regras` (JSON: `ignorar_conversa_aberta`, `max_por_dia`), `agendada_para`, `status`
(`rascunho` → `aguardando_aprovacao` → `aprovada` → `em_andamento` ⇄ `pausada` → `concluida`;
`cancelada` de qualquer uma), `simulacao` (JSON da última prévia), `criado_por`, `aprovado_por`,
`aprovado_em`, `iniciada_em`, `concluida_em`, `pausada_motivo`, totais.
Destinatário: um por contato (`unique(campaign_id, contact_id)`), `telefone` e `variaveis` congelados na
montagem, `status` (`pendente` | `enviando` | `enviado` | `entregue` | `lido` | `respondido` | `falhou` |
`pulado`), `motivo` (texto curto do guard, ex.: `optout`, `conversa_aberta`, `frequencia`,
`sem_whatsapp`, `variavel_vazia`), `wamid`, `whatsapp_message_id`, carimbos, `erro`, `codigo_erro`,
`tentativas`.
Evento: trilha humana (`criada`, `simulada`, `enviada_para_aprovacao`, `aprovada`, `iniciada`,
`pausada`, `retomada`, `cancelada`, `concluida`, `disjuntor`) com `user_id` e `detalhes`.

### 4.7 Mensagens (`whatsapp_messages`)
Toda mensagem que saiu ou chegou por um remetente, inclusive o histórico importado: `sender_id`,
`contact_id` (pode ser nulo), `telefone`, `direcao` (`inbound` | `outbound`), `wamid` (índice único quando
presente), `tipo`, `template_nome`, `conteudo` (texto; para template, resumo com as variáveis),
`status` (`pending` | `sent` | `delivered` | `read` | `failed` | `received`), `codigo_erro`, `erro`,
`origem` (`campanha` | `regua` | `manual` | `historico_kapso` | `broadcast_kapso` | `webhook`),
`campaign_recipient_id`, `journey_enrollment_id`, `context_wamid` (a que responde),
`kapso_conversation_id`, `enviado_em`, `entregue_em`, `lido_em`, `payload` (JSON cru, enxuto).
A entrega **só avança** (`sent` < `delivered` < `read`); "lida" antes de "entregue" marca os dois; falha
tardia não apaga entrega já confirmada (regra do webhook do KYC).

### 4.8 Supressões (`suppressions`)
`telefone_hash` (sha256 do E.164, único por remetente: `unique(telefone_hash, sender_id)`), `telefone`
(nulo após anonimização), `sender_id` (nulo = todos), `motivo` (`pediu_para_parar` | `denunciou` |
`meta_marketing_stopped` | `manual` | `importacao` | `historico`), `escopo` (`tudo` | `marketing`),
`wamid`, `detalhes`, `criado_em`. `Suppression::bloqueia($telefone, $sender, $categoria)` é a única
pergunta que o envio faz.

### 4.9 Réguas (`journeys`, `journey_steps`, `journey_enrollments`)
Régua: `nome`, `sender_id`, `gatilho` (`manual` | `lista` | `tag` | `segmento`), `gatilho_ref`, `ativa`,
`sair_ao_responder` (padrão sim: respondeu, vira conversa humana), `max_por_dia`.
Passo (ordenado): `tipo` (`template` | `aguardar` | `condicao` | `tag` | `encerrar`), `template_nome`,
`template_idioma`, `variaveis`, `aguardar_horas`, `condicao` (`sempre` | `se_nao_respondeu` |
`se_respondeu` | `se_nao_entregue`), `tag_id`.
Inscrição: `passo_atual`, `status` (`ativa` | `concluida` | `saiu` | `pausada` | `falhou`),
`proximo_em`, `motivo_saida`, carimbos. Uma inscrição ativa por (régua, contato).

### 4.10 Outras
`sender_quality_snapshots` (qualidade/tier/status por hora, para o gráfico e o alerta),
`webhook_calls` (append-only, 30 dias, sem PII além do payload enxuto), `users` + spatie permission.

## 5. Guardas do envio `[código]` `[decisão]`

Ordem em que `Guardas::motivoParaNaoEnviar(Contact, Sender, MessageTemplate, regras)` recusa; o primeiro
motivo vale e vai para `campaign_recipients.motivo`:

| Motivo | Regra |
|---|---|
| `envio_desabilitado` | `DISPARO_ENVIO_HABILITADO=false` (simulação global) |
| `remetente_pausado` | `senders.pausado_em` (manual ou disjuntor) ou `ativo=false` |
| `qualidade_red` | última qualidade conhecida do remetente é RED |
| `fora_da_janela` | agora fora de `janela_inicio`-`janela_fim` no fuso do remetente (espera, não falha) |
| `teto_diario` | envios `outbound` de template do remetente nas últimas 24 h ≥ teto efetivo (aquecimento, YELLOW) |
| `contato_invalido` | `status` ≠ `ativo` (optout, invalido, anonimizado) |
| `suprimido` | `suppressions` para o telefone (escopo `tudo`, ou `marketing` + template MARKETING) |
| `categoria_nao_permitida` | categoria do template fora de `senders.categorias_permitidas` |
| `template_nao_aprovado` | template não `APPROVED` na Meta |
| `sem_whatsapp` | `sem_whatsapp_em` há menos de 90 dias |
| `conversa_aberta` | template do mesmo remetente ao telefone nos últimos 7 dias (campanha pode ignorar só para UTILITY) |
| `frequencia` | ≥ 2 templates do remetente ao contato nos últimos 30 dias |
| `duplicado` | telefone repetido na mesma campanha |
| `variavel_vazia` | variável do template sem valor e sem padrão para este contato (a Meta recusa vazio) |

Disjuntor (`ExecutorDeCampanha`): `WhatsAppException::afetaTodos()` → pausa a campanha
(`pausada_motivo`), pausa o remetente (`pausado_motivo`), alerta no Discord, e marca `sonda_em` no
remetente: a próxima rodada manda 1 envio; se passar, despausa. `transitoria()` → espera 2^n s, até 3
vezes, depois pausa a campanha sem pausar o remetente.

## 6. Classificação da resposta `[código]`

Portado do `KapsoWebhookController` do KYC (regras conferidas em produção, 32 achados corrigidos em
08/10/2026). Normalização: minúsculas, sem acento, sem pontuação, espaços colapsados, sem enfeite
("pode", "por favor", "obrigado") em volta.

- **Parar em qualquer conversa, a qualquer tempo**: a mensagem inteira é "pare", "parar", "stop",
  "descadastrar", "sair da lista", "parar promoções"...; ou frase com a mensagem/contato/lista como objeto
  ("para de me mandar mensagem", "não me manda mais nada", "me exclua da lista"). Também o botão
  **"Parar promoções"** da Meta e **"Denunciar"**/**"Sair da lista"** num template nosso.
- **Parar em resposta a um template nosso** (mensagem citando um template, ou template nos últimos 7 dias):
  "não quero", "não tenho interesse", "cancelar", "chega", "bloquear", "sair"...
- **"Agora não"** em resposta a template: "não", "agora não", "prefiro não", "não preciso" - marca o
  destinatário como respondido/recusou, **não** bloqueia.
- Qualquer outra resposta: `respondido` no destinatário/inscrição, `ultima_resposta_em` no contato, a
  régua sai (conversa humana), e a mensagem aparece em **Conversas** para alguém tratar.
- Frases do atendimento ("não quero receber pelo Pix", "descadastrar o cartão") **não** bloqueiam.

## 7. Importação do histórico da Kapso `[código]`

`disparo:importar-historico-kapso {sender} [--desde=] [--ate=] [--contatos] [--broadcasts]`:
1. `GET /meta/whatsapp/v24.0/{phone_number_id}/conversations?fields=kapso()&last_active_since=` (cursor
   `after`): cria/atualiza o contato (`origem=kapso`, `nome` = `kapso.contact_name`), guarda
   `kapso_conversation_id`, `ultima_resposta_em` = `kapso.last_inbound_at`, `ultimo_envio_em` =
   `kapso.last_outbound_at`.
2. `GET /meta/whatsapp/v24.0/{phone_number_id}/messages?fields=kapso()&since=&until=` (cursor `after`):
   uma linha em `whatsapp_messages` por `wamid` (`origem=historico_kapso`), com direção, tipo, nome do
   template, status e texto; inbound com texto passa pelo classificador em modo histórico (só "parar
   sempre" e botões explícitos) e gera supressão `historico`.
3. `--contatos`: `GET .../contacts?fields=kapso()` completa nomes.
4. `--broadcasts`: `GET /platform/v1/whatsapp/broadcasts` + `/recipients` (as listas mandadas pelo painel
   da Kapso): cada destinatário vira mensagem `broadcast_kapso` com o status.
5. Guarda `historico_importado_ate` no remetente; a execução seguinte parte dali. Idempotente por `wamid`.
Limite da Kapso: 100 por página; 1000 req/min no plano Pro (`X-RateLimit-Remaining` observado; 429 espera
`Retry-After`).

Preferências de marketing (`GET /platform/v1/whatsapp/contacts/{telefone}/marketing_preferences`) são
consultadas uma a uma, então só sob demanda: ação "Conferir preferências na Meta" no público de uma
campanha MARKETING e no detalhe do contato; `stopped` vira supressão `meta_marketing_stopped` de escopo
`marketing` naquele remetente.

## 8. Comandos e agenda `[código]`

| Comando | Agenda | O que faz |
|---|---|---|
| `disparo:executar-campanhas` | a cada minuto, `withoutOverlapping` | para cada campanha `em_andamento` (ou `aprovada` com `agendada_para` vencido), um lote por remetente, respeitando ritmo, janela e teto |
| `disparo:processar-reguas` | a cada 10 min | inscrições com `proximo_em` vencido; gatilho `segmento` reavaliado às 09:30 |
| `disparo:sincronizar-remetentes` | de hora em hora | número, qualidade, tier, saúde → snapshot; mudança de qualidade alerta no Discord; RED pausa |
| `disparo:sincronizar-templates` | a cada 6 h | cache dos templates de cada remetente |
| `disparo:importar-historico-kapso` | manual (ou diário, às 03:00, para remetentes Kapso) | seção 7 |
| `disparo:importar-csv` | manual | o mesmo que a tela de importação |
| `disparo:importar-sellers-inativos` | manual | unico-api (seção 9) |
| `disparo:registrar-webhook {sender}` | manual | cadastra o webhook do número na Kapso |
| `disparo:diagnostico` | manual | cron pelo batimento, worker, chaves, webhooks, remetentes |
| `disparo:podar` | diário | `webhook_calls` > 30 dias |

Batimento do agendador em cache (`disparo:scheduler:heartbeat`), como no MED: sem cron, o diagnóstico
acusa.

## 9. Sellers inativos (unico-api) `[código]` `[inferido]`

Fonte: o banco do unico-api, com usuário **só de leitura** e GRANT por coluna, no mesmo desenho da régua
de ativação do KYC (`FonteUnicoApi`). Conexão `unicoapi` (`UNICOAPI_DB_*`); sem ela o comando diz que
está desligado. Consultas sem `*`, sem join, por tabela: `accounts` (`id`, `status`, `kyc_status`,
`created_at`), `transactions` (`account_id`, `payment_status`, `created_at`). Inativo = conta `status=1`
(ativa), `kyc_status='approved'`, sem transação paga (`payment_status=3`) nos últimos N dias
(`--dias=90`, padrão) ou nunca. O telefone **não** está nessas colunas (o KYC não o lê): vem do cadastro
(`GET /api/kyc-panel/accounts/{account_id}/register`, `UNICOPAG_API_*`, mesma rota que o MED usa), em
lote, com cache. O que não tem telefone entra como contato `invalido` com `account_id`, para o comercial
completar. Tudo isto é hipótese de colunas até ser conferido no RDS: marcar `[prod]` quando rodar.

## 10. Webhooks `[código]`

- `POST /api/webhooks/kapso/{sender}`: HMAC-SHA256 do corpo cru com `webhook_secret` do remetente
  (`X-Webhook-Signature`), evento em `X-Webhook-Event`, `X-Idempotency-Key` deduplica, batch com
  `batch=true`. Responde 200 em menos de 10 s; o trabalho pesado vai para a fila.
- `POST /api/webhooks/meta`: `GET` responde o `hub.challenge` (`META_WEBHOOK_VERIFY_TOKEN`); `POST` com
  `X-Hub-Signature-256` (sha256 HMAC com `META_APP_SECRET`); `entry[].changes[].value` com `statuses` e
  `messages`, `metadata.phone_number_id` acha o remetente.
- Os dois viram o mesmo evento interno (`EventoDeMensagem`): status (`sent|delivered|read|failed` +
  erro) ou mensagem recebida (`from`, `wamid`, `tipo`, texto/botão, `context.id`).
- `whatsapp.message.failed` com `131026` → `sem_whatsapp_em`; `131050`/`131049` → supressão
  `meta_marketing_stopped`; `131047` (janela fechada) em texto livre → só marca falha.

## 11. Painel `[código]`

Grupos: **Contatos** (Contatos, Bases, Tags, Segmentos, Importações, Bloqueios), **Disparos**
(Campanhas, Réguas, Conversas), **Configuração** (Remetentes, Templates), **Sistema** (Usuários).
Dashboard: qualidade e teto de cada remetente, envios/entregas/respostas/opt-outs de hoje e 7 dias,
campanhas em andamento, respostas sem tratamento.

Permissões (`App\Support\Permissions`): `view contacts`, `manage contacts`, `import contacts`,
`view pii`, `view campaigns`, `manage campaigns`, `approve campaigns`, `manage journeys`,
`manage senders`, `manage users`. Papéis: Super Admin (tudo), Admin (tudo menos usuários), Operador
(contatos, importação, campanhas e réguas sem aprovar, sem PII), Visualizador (só ver). Toda ação visível
só com `Permissions::allows(...)`; o Filament recusa no servidor chamar ação escondida.

## 12. Estado da suíte de testes

PHPUnit, SQLite isolado por processo (`tests/bootstrap.php`, igual ao MED), `Http::preventStrayRequests()`
em todo teste: Kapso e Meta sempre por `Http::fake`. Cobertura esperada: normalização de telefone,
classificador de resposta (as listas do KYC), guardas (cada motivo), montagem de componentes (posicional,
nomeado, cabeçalho, botão de URL), executor (ritmo, disjuntor, sonda), webhooks (assinatura, status,
resposta, opt-out, idempotência), importação CSV e Kapso, segmento, réguas, permissões do painel.

## 13. Em aberto

- Conferir no RDS as colunas de `accounts` para os sellers inativos (seção 9) e criar o usuário só de
  leitura (SQL de GRANT no KYC KB 8.23).
- Rota de lista de contas no unico-api (hoje só há o `register` por conta).
- Número para as ofertas da UnicoDrop: o comercial (RED em 26/10) ou um número novo aquecido do zero.
- Templates MARKETING novos para as bases compartilhadas: a Meta exige opt-out claro no texto.
- MCP (`mcp/`) como cliente da API da plataforma, em vez de falar direto com a Meta.

### 8.1 Como os comandos se comportam `[código]` (frente A, 28/10/2026)

- `disparo:executar-campanhas [--campanha=ID]`: a cada minuto, por remetente, UMA rodada da campanha mais
  prioritária pronta (`em_andamento`, ou `aprovada` com `agendada_para` vencido - aí ela vira `em_andamento`
  com evento `iniciada` automático). Orçamento global de 50 s contado do começo do comando; desfechos que não
  ocupam o remetente (`aguardando_tranche`, `categoria_mudou`, `aprovacao_invalidada`, `template_desconhecido`)
  deixam a próxima campanha do mesmo remetente rodar no mesmo tick. Saída sem PII.
- `disparo:sincronizar-remetentes [--rapido]`: `numero(fresco)` por remetente ativo, grava qualidade/tier/status/
  display/verified/WABA/saúde (sem chaves) e um `SenderQualitySnapshot`; transições: qualquer→RED =
  `bloquearPorQualidade` + Discord; qualquer→YELLOW = pausa campanhas MARKETING `em_andamento` + Discord;
  RED/YELLOW→GREEN = `aquecimento_inicio = hoje` + Discord (o bloqueio por qualidade continua até humano religar).
  `--rapido` só remetentes com campanha em andamento.
- `disparo:sincronizar-templates [sender]`: template que sumiu da WABA vira DISABLED; categoria que mudou alerta
  no Discord; lista vazia com cache cheio não desativa nada (precaução).
- `disparo:registrar-webhook {sender}`: rotaciona o segredo, registra `/api/webhooks/kapso/{webhook_key}` com os
  eventos de config + `whatsapp.contact.marketing_preference_changed`; se a Kapso recusar, o segredo antigo volta.
  Mostra o segredo uma vez; `secret_key` da resposta nunca vai à trilha.
- `disparo:registrar-numero {sender} status|solicitar|verificar|registrar` (provider meta): portado do
  `mcp/src/registrar.ts` para `MetaCloudClient::solicitarCodigo/verificarCodigo/registrarNumero`.
- `disparo:diagnostico`: falha (exit 1) sem batimento do agendador (`disparo:scheduler:heartbeat`, cache) ou do
  worker (`disparo:worker:heartbeat`, gravado pelo `WorkerHeartbeatJob` despachado a cada 5 min), sem pepper,
  remetente Kapso sem segredo ou sem webhook ativo com a URL desta instalação; avisa simulação, API_KEY e
  Discord vazios, `webhook_calls` sem processar há mais de 10 min, falhas de envio em 24 h.
- `disparo:processar-reguas [--segmentos]` e `disparo:podar` só chamam `ProcessadorDeReguas` e
  `Importacao\Retencao` (outras frentes) quando existirem; sem a classe avisam e saem com sucesso.
- Agenda (`routes/console.php`, tudo com `timezone('America/Sao_Paulo')` antes do horário, `withoutOverlapping(N)`
  em minutos e saída em `storage/logs/disparo-agendado.log`): executar-campanhas a cada minuto (5); processar-reguas
  a cada 10 min (30) e `--segmentos` 09:30; sincronizar-remetentes de hora em hora e `--rapido` a cada 10 min;
  sincronizar-templates a cada 6 h; importar-historico-kapso `--todos` 03:00 e `--todos --incremental` a cada 15
  min só com campanha em andamento; podar 04:00.

### 10.1 Como os webhooks se comportam `[código]` (frente A, 28/10/2026)

- Kapso: `POST /api/webhooks/kapso/{webhook_key}` (chave aleatória de 48 caracteres do remetente; desconhecida =
  404). Assinatura HMAC-SHA256 do corpo cru com `senders.webhook_secret` - sem segredo ou inválida = 401, sem
  fallback de `.env`. `phone_number_id` do corpo diferente do remetente da URL = ignorado (202, log sem PII).
  `whatsapp.message.received` só conta com `kapso.direction = inbound`. Tudo roda INLINE antes do 200 (o "pare"
  está gravado quando a Kapso recebe a resposta). `X-Idempotency-Key` vira `webhook_calls.idempotency_key` com
  `insertOrIgnore` antes do processamento e `processado_em` no fim: entrega repetida já processada = 200 sem
  repetir; lote que ficou pela metade (500) roda de novo na reentrega. `webhook_calls.payload` guarda só
  contagens e os resumos do processador (ids, classificação) - nunca o corpo.
- Status de wamid desconhecido (painel da Kapso, KYC no mesmo número) vira linha `origem=webhook` e conta em
  `Sender::templatesNasUltimas24h()`; `pricing.category` do status vira `payload.categoria`.
- Preferência de marketing (`whatsapp.contact.marketing_preference_changed`: `status`/`value` stop|stopped|
  resume|resumed ou `marketing_allowed`) = supressão `meta_marketing_stopped` de escopo `marketing` no remetente;
  resume libera só ela. Formato do payload da Kapso para esse evento ainda `[inferido]` (campos lidos de forma
  tolerante: `phone_number`, `contact.phone_number`, `contact.wa_id`, `wa_id`).
- Meta: `GET /api/webhooks/meta` (hub.verify_token com `hash_equals`), `POST` com `X-Hub-Signature-256`;
  `metadata.phone_number_id` sem remetente `provider=meta` = 200 sem persistir; `user_preferences`
  (`marketing_messages`, stop|resume) = a mesma supressão de marketing.
- API v1 (`X-API-Key` = `API_KEY`; vazio = 503, errada = 401): `GET /api/v1/health`, `GET|POST /api/v1/suppressions`
  (por hash; `since` lê `updated_at`, inclui liberadas; POST aceita telefone ou hash), `GET /api/v1/senders/{id}/
  templates`, `GET /api/v1/contacts/{telefone}` (mascarado), `POST /api/v1/campaigns` (rascunho, `criado_por`
  nulo) e `GET /api/v1/campaigns/{id}`. Aprovar/iniciar/pausar/cancelar ficam fora: quatro olhos só no painel.

## Histórico de atualizações

### 2026-10-28 - Frente A: webhooks, API v1, comandos e agenda
Implementados conforme as seções 8.1 e 10.1 (testes em `tests/Feature/Webhooks`, `Api` e `Console`). Correções
em relação ao desenho original: processamento do webhook inline (não na fila), idempotência com
`insertOrIgnore` e `processado_em`, chave aleatória na URL em vez do id do remetente, segredo por remetente sem
fallback de `.env`, `pricing.category` do status, wamid desconhecido contando no teto.


### 2026-10-28 - Nascimento da plataforma
Repositório reorganizado: a CLI/MCP em TypeScript foi para `mcp/`; a raiz passa a ser a aplicação Laravel
com painel Filament. Decisões da seção 2 registradas a partir do incidente do número comercial (KB do KYC
8.23) e do pedido do operador de 28/10/2026 ("ferramenta de disparos para recuperação e ativação de
sellers; bases compartilhadas com a UnicoDrop; base de sellers inativos; gerir e nutrir esses contatos";
"na Kapso possui todo o histórico").
