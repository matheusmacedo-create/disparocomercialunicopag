# Base de conhecimento - ÚnicoPag Disparos

Memória do sistema: como a plataforma de disparos **se comporta**, as decisões tomadas e o que ainda está
em aberto. Mesma regra do KYC (`unico-pag/unicopag-kyc`, `doc/KYC_KNOWLEDGE_BASE.md`) e do MED: leia
antes de mexer no fluxo; atualize quando descobrir algo não óbvio; o que precisa ser feito vai para card,
o que o sistema faz fica aqui.

Marcações: `[código]` conferido no código e coberto por teste · `[prod]` medido em produção ·
`[decisão]` regra decidida pela operação · `[inferido]` dedução ainda não confirmada (ex.: contrato da
Kapso lido na documentação pública, nunca rodado contra a conta) · `[revisão]` achado da revisão
adversarial de 28/10/2026 já incorporado ao desenho.

## 1. O problema que a plataforma resolve

A ÚnicoPag dispara WhatsApp para três públicos, com três intenções:

| Público | De onde vem | O que se quer | Categoria Meta | `objetivo` |
|---|---|---|---|---|
| **Sellers inativos** (conta aprovada que parou de vender ou nunca vendeu) | unico-api (contas), CSV do comercial | **recuperar** | MARKETING (oferta) ou UTILITY só com dado específico e verificável da conta | `recuperar` |
| **Sellers novos** (aprovados, configurando) | KYC (a régua de ativação já existe lá) | **ativar** | UTILITY | `ativar` |
| **Bases compartilhadas com a UnicoDrop** (lojistas, leads) | CSVs da UnicoDrop, listas do painel da Kapso | **ofertar** | MARKETING, sempre | `ofertar` |
| Avisos de conta | plataforma | **avisar** | UTILITY | `avisar` |

Até 28/10/2026 isso era feito por (a) listas montadas à mão no painel da Kapso (número comercial), (b) a
CLI/MCP em TypeScript deste repositório (hoje em `mcp/`, direto na Cloud API da Meta, sem base de contatos
nem histórico, hoje com envio real desligado) e (c) a régua do KYC (só o funil de cadastro e ativação).

**O incidente que define as regras daqui** `[prod 26/10/2026, KB do KYC 8.23]`: o número comercial
(phone `1475615155627193`, WABA `1753802449254685`) caiu para qualidade **RED** depois de **650 envios
frios num dia**, com bloqueios e denúncias. A Meta mede a qualidade pelas denúncias e bloqueios dos últimos
7 dias e **não avisa quem bloqueou**: o que recupera é parar o frio e mandar mensagem que o cliente espera.
Logo: **a plataforma existe para disparar menos e melhor**, e o que a protege não é só o erro de API (que
nunca vem no bloqueio): é a leva pequena, a leitura dos webhooks antes de seguir, o opt-out para sempre, o
teto amarrado ao tier da Meta, a janela de horário e a trilha de tudo.

## 2. Decisões de produto `[decisão]`

1. **Simulação por padrão.** `DISPARO_ENVIO_HABILITADO=false` no ambiente faz a campanha rodar inteira
   sem mandar nada: os destinatários viram `simulado` com o motivo real de quem seria pulado, e a prévia
   mostra exatamente o que sairia. O interruptor **não é guarda** - a prévia avalia as mesmas regras com
   ele ligado ou desligado `[revisão]` `[código]`. Só liga quem administra o servidor, depois de importar
   o histórico da Kapso de cada número.
2. **Quatro olhos de verdade.** Campanha só sai depois de **prévia posterior à última edição** (hash) e
   **aprovação por outro usuário**, que digita o número de destinatários e vê custo, teto restante e em
   quantos dias sai. Quem criou só aprova a própria campanha por **auto-aprovação explícita**: espera
   obrigatória (30 min) antes de começar e teto por campanha (200); acima disso, segundo usuário - sem
   exceção para Super Admin `[revisão]` `[código]`. **Aprovar materializa os destinatários** (telefone e
   variáveis congelados) e grava um hash do que foi aprovado (destinatários, template, política do
   remetente); se algo mudar antes do envio, a campanha volta para aprovação. Régua tem a mesma máquina de
   estados (rascunho → aguardando aprovação → aprovada → ativa) `[revisão]` `[código]`.
3. **Opt-out é para sempre e vale para o número**, não para a base nem para o remetente: "pare", "sair da
   lista", o botão "Parar promoções" da Meta, "Denunciar" num template, ou o pedido coloquial ("não me
   manda mais nada") bloqueiam o telefone em todos os remetentes. Fica em `suppressions` (escopo `tudo`
   **sempre sem remetente** - invariante do model) com o HMAC do telefone, para sobreviver à anonimização,
   e em `contacts.status = optout`. Desfazer um falso positivo só com `release suppressions`, justificativa
   e trilha; **nunca** para denúncia nem para a preferência da Meta `[revisão]` `[código]`.
4. **Preferência de marketing da Meta** (`131050`, HTTP 422 `marketing_preference_stopped`, evento
   `marketing_preference_changed`, destinatário `suppressed` de broadcast) bloqueia só template
   **MARKETING** naquele remetente; `resume` solta só essa supressão. **`131049` não é opt-out**: é a
   Meta limitando marketing ao usuário por ora → carência de 7 dias, nunca supressão `[revisão]` `[código]`.
   **Regra da casa mais forte que a da Meta:** campanha com `objetivo` comercial (`recuperar`, `ofertar`)
   nunca sai como UTILITY para quem parou marketing ou recusou - usar utilidade para contornar o opt-out de
   marketing é "abuso de categoria" `[revisão]` `[código]`.
5. **Um remetente = um número = uma política**: categorias permitidas (o número do KYC só UTILITY; o
   comercial MARKETING e UTILITY), teto diário, ritmo, janela, aquecimento. A plataforma nunca manda pelo
   número do KYC. **O comercial é compartilhado com o KYC** (avisos de primeira venda, KB do KYC 8.23):
   ver 2.15 e 13.
6. **Kapso é o provedor principal** (histórico, webhook assinado, preferências de marketing, broadcasts).
   A Cloud API direta da Meta continua suportada (provider `meta`) para número fora da Kapso. Os dois
   falam a mesma Graph API; só a autenticação muda `[código]`.
7. **Histórico vem da Kapso.** Pedido do operador (28/10/2026): "na Kapso possui todo o histórico". A
   importação lê conversas, mensagens, contatos e broadcasts de cada número e reconstrói quem já recebeu o
   quê, quem respondeu, quem pediu para parar (com o contexto da mensagem citada), quem não tem WhatsApp.
   **Só metadados**: o texto do cliente passa em memória pelo classificador e não é gravado `[revisão]`.
   Antes de qualquer campanha para uma base velha importa-se o histórico; a prévia avisa quando o histórico
   do número tem mais de 24 h.
8. **Conversa aberta não recebe outro template** por 7 dias (`DISPARO_CONVERSA_ABERTA_DIAS`), em qualquer
   campanha do mesmo remetente. Campanha UTILITY pode ignorar; régua ignora por padrão (é a mensagem
   esperada) e respeita só o intervalo mínimo dela (48 h) `[revisão]` `[código]`.
9. **Frequência**: MARKETING por telefone em **todos** os remetentes, 1 a cada 30 dias e 3 a cada 90;
   campanha UTILITY 2 por remetente em 30 dias; régua até 4 em 30 dias. "Não"/"agora não" a oferta =
   carência de 90 dias; duas recusas = supressão de marketing `[revisão]` `[código]`. `131026` (sem
   WhatsApp) tira o contato por 90 dias. Template `sent` sem `delivered` por 48 h = provável bloqueio,
   fora de MARKETING por 180 dias.
10. **Janela**: 08:00-20:00 por padrão, avaliada **no fuso do contato** (DDD: Acre, Amazonas, MT, MS, RO,
    RR) e nos dias da semana da campanha (MARKETING: segunda a sexta por padrão; domingo nunca). Fora dela
    a fila espera, não falha `[revisão]` `[código]`.
11. **Teto e aquecimento**: teto efetivo = min(teto configurado, aquecimento, 80% do tier da Meta lido da
    API), cortado pela metade em YELLOW; RED ou bloqueio por qualidade = zero. Nenhum remetente passa de
    `DISPARO_TETO_MAXIMO_ABSOLUTO` (1000), mesmo editado no painel. O aquecimento começa em 50/dia e só
    sobe (×1,5) a cada dia **medido GREEN** nos snapshots; remetente que volta de RED/YELLOW recomeça do
    zero. MARKETING usa no máximo 30% do teto; 30% fica reservado para as réguas. O teto conta **tudo o
    que saiu pelo número**, inclusive o que o webhook viu sair pelo painel da Kapso ou pelo KYC
    (`origem=webhook`) `[revisão]` `[código]`.
12. **Tranches e disjuntores** `[revisão]` `[código]`:
    - campanha MARKETING sai em **levas**: a primeira = min(5%, 50); depois espera 90 min lendo os
      webhooks; o **disjuntor de engajamento** julga (com amostra ≥ 20): `sent` sem `delivered` após 30
      min ≥ 10%, pedidos para parar ≥ 1% dos entregues, 131049 ≥ 5%, 131026 ≥ 5%, lidos < 30% em 2 h → pausa
      a campanha e **bloqueia o remetente por qualidade**;
    - **bloqueio por qualidade** (131048, 368, 131031, qualidade RED, engajamento): pausa o remetente
      **sem sonda**, cancela as campanhas MARKETING em andamento dele; só humano com `manage senders`
      religa, e o aquecimento recomeça;
    - **conta impedida** (pagamento 131042/141006, token 190/131005, saldo da Kapso, Sandbox, 401-404):
      pausa o remetente e manda **1 sonda a cada 120 min** (`sonda_intervalo_minutos`); se passar,
      despausa;
    - **erro do template** (132000/132001/132012/132015/132016): pausa a campanha, não o remetente;
    - **transitória** (429, 5xx, `timeout` antes do POST): no máximo 2 tentativas com 60 s entre elas;
      três seguidas pausam a campanha; `timeout_envio` (o POST pode ter chegado) é **final**:
      `enviado_incerto`, conta em teto, frequência e conversa aberta;
    - YELLOW no meio de campanha pausa as campanhas MARKETING do remetente; com campanha em andamento a
      qualidade é lida a cada 10 min.
13. **LGPD** `[revisão]` `[código]`: CPF/CNPJ criptografado e mascarado; telefone mascarado por padrão;
    texto do cliente só com `view conversations`; documento, e-mail, atributos do CSV, variáveis
    renderizadas e exportações só com `view pii`. Hashes de telefone e documento são **HMAC com
    `DISPARO_HASH_PEPPER`** (sha256 puro de celular se inverte por enumeração). "Anonimizar" troca o
    telefone por `anon:<hash12>` em todas as tabelas, zera PII e conteúdo, mantém a supressão pelo hash
    e registra em `contact_events`. Consentimento é **por contato e estruturado** (origem, canal, data,
    detalhes); template MARKETING a contato de origem externa (UnicoDrop, CSV) ou de base que exige
    consentimento só sai com consentimento registrado em nome da ÚnicoPag. Pedido do titular ("apague meus
    dados") é classe própria: bloqueia na hora e abre item em Conversas com prazo de 15 dias. Retenção:
    texto/payload/variáveis somem em 180 dias; lead externo sem interação por 12 meses é anonimizado;
    CSV apagado depois de processar.
14. **Nada de PII no log nem no Discord**: é mecanismo, não convenção - `Mask::scrub()` em toda mensagem
    de erro do provedor (a Meta ecoa valores recusados) e em tudo o que vai ao Discord; `payload` de
    mensagem e de webhook só com ids, tipos, status e códigos `[revisão]` `[código]`.
15. **Dono do número compartilhado** `[decisão pendente]`: hoje o KYC manda avisos de primeira venda pelo
    comercial sem consultar esta plataforma. Transição adotada: (i) esta plataforma registra o próprio
    webhook no comercial (a Kapso aceita vários por número) e trata status de wamid desconhecido como
    envio de fora, contando no teto e na conversa aberta; (ii) importação incremental do histórico a cada
    15 min enquanto houver campanha em andamento; (iii) `GET/POST /api/v1/suppressions` para o KYC ler e
    gravar pedidos para parar. Opção definitiva (o KYC enviar pela API desta plataforma) fica em 13.
16. **MCP/CLI em `mcp/` não é canal de envio**: envio real desligado (`MCP_ENVIO_REAL=true` só para
    emergência), registro movido para `mcp/mcp.json.example`. Caminho: cliente da `/api/v1` `[revisão]`.

## 3. Arquitetura em um minuto `[código]`

Laravel 12 + Filament 4 (painel em `/admin`), MySQL/MariaDB em produção (Forge, como o KYC e o MED),
SQLite nos testes, fila em banco, agendador por `schedule:run`, app em `America/Sao_Paulo`.

```
Remetente (sender) ──► WhatsAppClient (Kapso | Meta Cloud) ──► Meta
     │                        ▲
     │ webhook assinado        │ Despachante (grava `pending` ANTES do POST)
     ▼                        │
ProcessadorDeEventos ──► whatsapp_messages (trilha) ──► campaign_recipients / journey_enrollments
     │
     └─► ClassificadorDeResposta ──► suppressions + contacts (optout, recusa, carência, consentimento)
```

- `app/Services/WhatsApp`: `WhatsAppClient` (contrato), `GraphClient` (o que Kapso e Meta têm em comum),
  `KapsoClient` (platform/v1 + meta proxy com `kapso(default)`; conversas, mensagens, contatos,
  broadcasts, preferências, webhooks), `MetaCloudClient`, `WhatsAppClientFactory`, `WhatsAppException`
  (recusas traduzidas; classes `transitoria`/`qualidade`/`conta`/`template`/`contato`).
- `app/Services/Envio`: `Guardas`, `MontadorDeComponentes`, `Despachante`, `ExecutorDeCampanha`,
  `DisjuntorDeEngajamento`, `TravaDoRemetente` (uma trava por número para todo envio: campanha, régua,
  teste, sonda).
- `app/Services/Campanhas`: `FluxoDeCampanha` (transições com autorização no servidor), `MontadorDePublico`
  (prévia e materialização), `ValidadorDeCampanha` (problemas do template antes de qualquer envio).
- `app/Services/Reguas`: `FluxoDeRegua`, `ProcessadorDeReguas`.
- `app/Services/Webhooks`: `EventoDeMensagem` (formato interno comum), `ProcessadorDeEventos`,
  `ClassificadorDeResposta`.
- `app/Services/Importacao`: `ImportadorCsv`, `ImportadorKapso`, `ImportadorSellersInativos`, `Retencao`.
  `app/Services/Contatos`: `AnonimizarContato`, `ExportarDadosDoTitular`.
- `app/Support`: `Telefone` (E.164, nono dígito, `canonico()`, fuso por DDD, HMAC, `anonimo()`), `Mask`
  (documento, e-mail, `scrub()`), `Permissions`, `Variaveis`, `Ator`.
- `mcp/`: a CLI/MCP original em TypeScript.

## 4. Modelo de dados `[código]`

Tabelas e models em inglês, colunas de domínio em português, carimbos do Laravel em inglês; todo
TIMESTAMP nullable (MariaDB 10.6 daria `ON UPDATE CURRENT_TIMESTAMP` ao primeiro NOT NULL).

### 4.1 Remetentes (`senders`)
`provider` (`kapso` | `meta`), `finalidade` (`comercial` | `avisos` | `kyc` | `teste`), `phone_number_id`
(único), `waba_id`, `api_key` e `webhook_secret` criptografados (segredo gerado pela plataforma, sem
fallback de `.env`), `webhook_key` aleatória na URL do webhook, `categorias_permitidas` (null = só UTILITY),
`teto_diario` (≤ teto absoluto), `msgs_por_minuto`, `janela_inicio/fim`, `fuso`, `aquecimento_*`,
`pausado_em/motivo/por` (manual ou conta impedida), `sonda_em` + `sonda_intervalo_minutos`,
`bloqueio_qualidade_em/motivo`, `qualidade`, `limite_tier`, `status_meta`, `saude`, `sincronizado_em`,
`templates_sincronizados_em`, `historico_importado_ate`, `ativo`. Política alterada no painel vira
`sender_events` (de → para, sem segredos).

### 4.2 Contatos (`contacts`)
`telefone` E.164 com `+` (único; só anatomia brasileira: DDD 11-99 + fixo 8 dígitos iniciado em 2-5 ou
celular 9 dígitos iniciado em 9), `telefone_hash` (HMAC), `nome`, `primeiro_nome` (sem dígito, Title
Case; razão social de MEI não serve), `email`, `documento` (criptografado) + `documento_hash` (HMAC),
`empresa`, `origem` (`unicodrop` | `unicopag` | `csv` | `kapso` | `unicoapi` | `manual`; `unicodrop` e `csv`
são **externas**), `tipo` (`seller_inativo` | `seller_ativo` | `lead` | `cliente` | `outro`), `account_id`,
`atributos` (JSON das colunas extras do CSV → `{{atributos.x}}`; colunas sensíveis não entram),
`status` (`ativo` | `optout` | `invalido` | `anonimizado`), `optout_*`, `sem_whatsapp_em`,
`marketing_carencia_ate` (131049), `recusou_em` + `recusas_total`, `provavel_bloqueio_em`,
`consentimento_origem/em/canal/detalhes`, contadores (`envios_total`, `entregues_total`, `lidos_total`,
`respostas_total`, `falhas_total`, atômicos), `ultimo_envio_em`, `ultimo_template_em`,
`ultima_resposta_em`, `kapso_contact_id`, `observacoes`, `anonimizado_em`. Relações: `tags`, `lists`
(bases, com `adicionado_em` e a importação), `messages`, `campaignRecipients`, `journeyEnrollments`.
Trilha por contato em `contact_events` (chaveada pelo hash: anonimização, pedido LGPD, bloqueio, liberação,
consentimento, texto enviado, conversa lida).

### 4.3 Bases, tags e segmentos
`contact_lists` (`nome`, `slug`, `dono` `unicopag` | `unicodrop` | `compartilhada`, `exige_consentimento`
- travado em true quando o dono não é a ÚnicoPag -, `ativa`); `tags`; `segments` com `regras` JSON
traduzidas só por `ConsultaDeSegmento`: `listas`, `excluir_listas`, `tags`, `excluir_tags`, `origens`,
`tipos`, `status`, `sem_envio_ha_dias`, `sem_resposta_ha_dias`, `respondeu_ha_dias`, `criado_apos/antes`,
`sem_whatsapp`, `atributos` (`campo`/`operador`/`valor`), `recebeu_template`/`nao_recebeu_template` e
`campanha` (quem recebeu a campanha X com status Y).

### 4.4 Importações (`contact_imports`)
`tipo` (`csv` | `kapso` | `unicoapi` | `optout`), arquivo (apagado depois de processar), `mapeamento`,
base, remetente, origem, tipo do contato, tags, consentimento, `parametros`, `status`, contagens
(`total_linhas`, `criados`, `atualizados`, `ignorados`, `invalidos`, `duplicados`, `optouts`), `erros`
(primeiros 100 no banco; **todas** as linhas rejeitadas em `rejeitadas.csv` para baixar), `user_id`.
Regras do CSV: `,` ou `;` detectado, BOM, coluna de telefone detectada (`phone`, `telefone`, `whatsapp`,
`celular`, `numero`, `fone`), inválido = rejeitado, duplicado no arquivo = ignorado, telefone já existente
**atualiza** (precedência de nome `unicoapi > csv > kapso > manual`; vazio não apaga; atributos fazem
merge), contato `optout`/`anonimizado` entra na base e segue bloqueado; coluna com nome sensível (cpf,
cnpj, rg, endereço, nascimento, renda, senha) só vai para campo tipado ou é ignorada.

Como a importação de CSV se comporta `[código]` (`ImportadorCsv`, job `ProcessarImportacaoJob`):
- Mapeamento coluna => `telefone | nome | primeiro_nome | email | documento | empresa | account_id |
  atributos.<chave> | ignorar`, sugerido pelo cabeçalho (`sugerirMapeamento`). **Denylist**: coluna cujo nome
  (ou chave de atributo) contém cpf, cnpj, documento, rg, endereco, nascimento, renda ou senha só vai para
  `documento` (criptografado) ou `ignorar` - nunca para `atributos`, que viram variável de template em claro.
- Prévia (`previa()`): 10 primeiras linhas mapeadas e normalizadas + contagem prevista do arquivo inteiro
  (criados, atualizados, inválidos, duplicados, sem consentimento), sem gravar nada.
- Precedência do nome entre fontes (`contacts.nome_origem`): unicoapi/unicopag > csv/unicodrop > kapso >
  manual. O nome de uma planilha tem sempre precedência `csv`, qualquer que seja o rótulo de origem; a Kapso
  (perfil do WhatsApp) só preenche vazio e guarda o perfil em `atributos.whatsapp_profile`. Nome em CAIXA
  ALTA vira Title Case; nome sem letra (só emoji) não é nome; `primeiro_nome` nunca tem dígito.
- Origem é a da primeira vez que o telefone apareceu; o tipo é o que a importação diz; consentimento só
  preenche vazio. Base com `exige_consentimento` (ou origem externa) sem consentimento informado: importa
  mesmo assim e conta em `sem_consentimento` - a guarda `sem_consentimento` barra o MARKETING.
- Telefone já suprimido (pediu para parar, anonimizado, lista de opt-out) entra como `optout`.
- `tipo=optout`: cada telefone vira supressão `importacao` (escopo tudo, origem csv); nenhum contato é criado.
- TODAS as rejeitadas vão para `storage/app/private/importacoes/{id}/rejeitadas.csv` (linha, telefone
  mascarado, referência, motivo; `;` e BOM para o Excel) - `ContactImport::baixarRejeitadas()`; `erros`
  guarda só as 100 primeiras. O arquivo original é apagado depois de processar. Quem importou recebe
  notificação no sininho (sem PII).

### 4.5 Templates (`message_templates`)
Cache por remetente do que a Meta devolve (`nome` até 512, `idioma`, `categoria`, `status`,
`parameter_format`, `componentes`, `qualidade`). **O cache serve ao painel e à prévia; a decisão de envio
reconfere o template na Meta uma vez por dia por campanha** e, se a categoria mudou desde a aprovação
(`campaigns.template_categoria`), a campanha volta para aprovação. Template com `quality_score`
YELLOW/RED não sai como MARKETING.

### 4.6 Campanhas (`campaigns`, `campaign_recipients`, `campaign_events`)
Campanha: `objetivo`, remetente, `template_nome/idioma`, `template_categoria` (congelada na aprovação),
`prioridade` (1-9), `variaveis` (JSON: `corpo` lista posicional ou mapa nomeado com `{{variavel|padrão}}`,
`cabecalho_texto`, `cabecalho_midia_url/tipo`, `botao_url_sufixo`, `permitir_links` - só quem aprova),
`publico` (JSON: `listas`, `segment_id`, `campanha_anterior {campaign_id, status[]}`, `excluir_listas`,
`excluir_tags`; **público vazio é recusado**), `regras` (`ignorar_conversa_aberta` só UTILITY,
`max_por_dia`, `dias_da_semana`), `agendada_para`, `status` (`rascunho` → `aguardando_aprovacao` →
`aprovada` → `em_andamento` ⇄ `pausada` → `concluida`; `cancelada`), `simulacao` + `simulacao_hash`,
`custo_estimado`, `aprovacao_hash`, `tranches_enviadas`, `tranche_aguardando_ate`, `criado_por`,
`aprovado_por/em`, carimbos, totais (atômicos). `status`, aprovação e `criado_por` **fora do `$fillable`**:
só `FluxoDeCampanha` muda, com permissão checada no servidor.
Destinatário (um por contato): `telefone` e `variaveis` congelados, `status` (`pendente` | `enviando` |
`enviado` | `entregue` | `lido` | `respondido` | `falhou` | `pulado` | `simulado` | `enviado_incerto`),
`motivo` (guard ou código), `wamid`, carimbos, `convertido_em`/`conversao_origem`, `tentativas`,
`proxima_tentativa_em`.
Evento: trilha humana com `ator` gravado como texto (apagar usuário não apaga quem aprovou); rotina
agendada é `sistema`, nunca o usuário que estava com o painel aberto.

### 4.7 Mensagens (`whatsapp_messages`)
Tudo o que saiu ou chegou por um remetente: direção, `wamid` (único; `external_key` para broadcast sem
wamid), tipo, `template_nome`, `conteudo` (texto do cliente atrás de `view conversations`; resumo do
template com valores atrás de `view pii`; histórico importado **sem texto**), `status` (`pending` | `sent`
| `delivered` | `read` | `failed` | `received`), códigos de erro, `origem` (`campanha` | `regua` | `manual`
| `historico_kapso` | `broadcast_kapso` | `webhook` = saiu por fora), vínculos com destinatário/inscrição,
`context_wamid`, `kapso_conversation_id`, carimbos, `payload` (sem PII), `tratada_em/por`, `user_id`
(autor de texto do painel). A entrega **só avança** (`sent` < `delivered` < `read`); "lida" antes de
"entregue" marca os dois; falha tardia não apaga entrega confirmada; `pending` é o que saiu daqui e ainda
não foi confirmado (inclusive `timeout_envio`).

### 4.8 Supressões (`suppressions`)
`telefone_hash` (HMAC), `telefone` (nulo após anonimização), `sender_id` (nulo = todos; **obrigatoriamente
nulo em escopo `tudo`**), `chave` única (hash:remetente; ganha sufixo ao liberar), `escopo` (`tudo` |
`marketing`), `motivo` (`pediu_para_parar` | `denunciou` | `meta_marketing_stopped` | `recusas_repetidas` |
`manual` | `importacao` | `historico` | `anonimizado`), `origem`, `wamid`, `detalhes` (scrubbed),
`liberada_em/por/motivo`. `Suppression::bloqueia(telefone, remetente, categoria)` é a única pergunta do
envio; `createOrFirst` segura webhook e painel no mesmo segundo.

### 4.9 Réguas (`journeys`, `journey_steps`, `journey_enrollments`, `journey_events`)
Régua: remetente, `objetivo`, `gatilho` (`manual` | `lista` | `tag` | `segmento`) + referência, `status`
(mesma máquina da campanha) + `ativa`, `sair_ao_responder`, `sair_ao_converter`, `reinscrever_apos_dias`,
`ignora_conversa_aberta` (padrão sim), `intervalo_minimo_horas` (48), `max_por_dia` (obrigatório),
`criado_por`, `aprovado_por/em`. Passo: `template` | `texto` (texto livre só dentro das 24 h abertas pela
resposta, uma vez) | `aguardar` | `condicao` (`se_nao_respondeu` | `se_respondeu` | `se_nao_entregue` |
`se_nao_leu`) | `tag` | `encerrar`. Inscrição: `passo_atual`, `status` (`ativa` | `concluida` | `saiu` |
`pausada` | `falhou`), `chave_ativa` única enquanto ativa (uma por contato, mesmo com dois workers),
`proximo_em`, `motivo_saida` (`respondeu`, `pediu_para_parar`, `converteu`, ...), `convertido_em`.

#### 4.9.1 Como a régua roda `[código 28/10/2026]`

`ProcessadorDeReguas::rodar()` (a cada 10 min por `disparo:processar-reguas`), régua `status=ativa`, inscrições
`ativa` com `proximo_em` vencido, remetente a remetente sob a **mesma trava** das campanhas
(`TravaDoRemetente`; remetente ocupado é pulado na rodada). Com `DISPARO_ENVIO_HABILITADO=false` a rodada só
registra no log: régua não tem prévia nem "simulado" (marcar o passo avançaria a inscrição sem nada ter
chegado). Ordem do que respeita: `Guardas::motivoDoRemetente` (pausa, RED, janela, teto); orçamento do número
= teto efetivo − templates das últimas 24 h (a reserva de 30 % das réguas é delas, não é descontada) e o teto
de MARKETING; `max_por_dia` da régua (templates `origem=regua` das inscrições dela em 24 h); por contato as
guardas com `origem=regua`: **intervalo mínimo da régua** (`intervalo_minimo_horas`, estrito, só entre
templates de régua ao telefone neste remetente) no lugar da frequência de campanha, mais o total de 4 em 30 d
(`regua_max_30d`), e conversa aberta ignorada só em UTILITY quando `ignora_conversa_aberta`.

Passos (`passo_atual` é sempre o PRÓXIMO a executar): `template` envia (`Despachante`, `journey_enrollment_id`
na mensagem, `ultimo_wamid` na inscrição); guarda que só adia (janela no fuso do contato) → `proximo_em` na
próxima abertura; guarda que descarta ou recusa do contato (131026, 131050, variável vazia...) → o passo é
**pulado e registrado** em `passos_pulados` (`[{passo, tipo, motivo, em}]`, últimos 50) e `ultimo_motivo`;
`aguardar` → `proximo_em = agora + horas` e `passo_atual` já aponta para o seguinte; `condicao`
(`se_nao_respondeu` | `se_respondeu` | `se_nao_entregue` | `se_nao_leu`) olha o último template da inscrição e
`contacts.ultima_resposta_em` depois dele; não atendida → `concluida` com `condicao_nao_atendida`; `tag` anexa
(e dispara o gatilho `tag` de outras réguas); `texto` só com resposta do contato nas últimas 24 h
(`Despachante::enviarTexto`, uma vez; senão pulado como `janela_fechada`) - por isso só faz sentido logo depois
de um `aguardar`: a inscrição fica parada nele e o webhook (`ProcessadorDeEventos`) antecipa `proximo_em`
quando a resposta chega (`FluxoDeRegua::problemas` acusa texto sem `aguardar` antes); `encerrar` ou fim dos
passos → `concluida`. Recusa de conta/qualidade passa pelo `Disjuntor` compartilhado (abaixo) e para a rodada;
transitória reagenda a inscrição em 15 min, três seguidas param a rodada (`instabilidade`); a régua também
manda a sonda de 1 quando o remetente está impedido e a sonda venceu.

Gatilhos: `lista` e `tag` disparam no `attach()`/`sync()` das pivots `ContactListContact` e `ContactTag`
(observers em `app/Observers`; `DB::table()->insert()` direto passa por fora - chame
`ProcessadorDeReguas::inscreverPorGatilho()`); só contato `ativo` entra. `segmento`:
`reavaliarSegmentos()` (uma vez por dia) inscreve quem bate em `ConsultaDeSegmento` sem inscrição ativa;
quem já passou só volta depois de `reinscrever_apos_dias` (nulo = nunca). Conversão:
`JourneyEnrollment::converter($origem)` grava `convertido_em`/`conversao_origem` (a primeira fica) e, com
`sair_ao_converter`, encerra `saiu` com `converteu`. `sair_ao_responder` fica no `ProcessadorDeEventos`.

### 4.10 Outras
`sender_quality_snapshots` (qualidade/tier/status por hora: alimenta o aquecimento e o gráfico),
`sender_events`, `contact_events`, `webhook_calls` (append-only, 30 dias, resumo sem PII, chave de
idempotência), `users` (+ `ativo`: desativar em vez de apagar; `telefone_teste`: único destino do
"Enviar teste").

## 5. Guardas do envio `[código]`

Duas tabelas, porque dois efeitos diferentes:

**Adiam a rodada** (o destinatário continua pendente): `remetente_pausado`, `qualidade_red`,
`template_nao_aprovado`/`categoria_nao_permitida`/`template_qualidade` (estes pausam a campanha),
`fora_da_janela`, `teto_diario`, `dia_da_semana`, `fora_da_janela_contato`.

**Descartam o destinatário** (`campaign_recipients.motivo`), nesta ordem: `optout`, `contato_invalido`,
`suprimido`, `sem_consentimento` (MARKETING a origem externa ou base que exige), `sem_whatsapp` (90 d),
`provavel_bloqueio` (MARKETING, 180 d), `marketing_carencia` (131049 ou objetivo comercial), `recusou`
(90 d), `conversa_aberta`, `frequencia`, `duplicado`, `variavel_vazia`/`variavel_com_link`/`variavel_longa`.

Orçamento da rodada = teto efetivo − saídos nas 24 h (qualquer origem) − reserva das réguas não usada;
MARKETING ainda limitado a 30% do teto; `max_por_dia` da campanha; ritmo `msgs_por_minuto`; rodada de
até 50 s por tick; `enviando` órfão há 10 min vira `enviado_incerto`.

Disjuntor (`App\Services\Envio\Disjuntor`, compartilhado por campanhas e réguas `[código 28/10/2026]`):
`contaImpedida()` (pagamento, token, saldo, Sandbox, 401-404) → pausa o remetente com `sonda_em =
+sonda_intervalo_minutos` (120) e pausa a campanha de origem; `bloqueioPorQualidade()` (131048, 368, 131031,
disjuntor de engajamento) → trava o remetente sem sonda e **cancela** as campanhas MARKETING em andamento dele
(UTILITY de origem só pausa); os dois registram `sender_events` e alertam no Discord. A sonda sai pela campanha
pausada pelo disjuntor (ou por uma régua ativa do remetente): se passa, o remetente despausa e a campanha
pausada automaticamente volta a `em_andamento` (evento `retomada` automático). `transitoria()` → espera 2^n s
(`lote.backoff_maximo_segundos`), até 2 tentativas por destinatário com 60 s entre elas; três transitórias
seguidas pausam a campanha sem pausar o remetente. A prévia pré-carrega o histórico por lote
(`Guardas::historicoPorTelefone`: último template, último de régua, contagem 30 d por remetente, MARKETING
30/90 d global) e `motivoDoContato` aceita esse mapa em vez de consultar o banco por contato.

## 6. Classificação da resposta `[código]`

Portado do `KapsoWebhookController` do KYC (regras conferidas em produção, 32 achados corrigidos em
08/10/2026). Normalização: minúsculas, sem acento, sem pontuação, espaços colapsados, sem enfeite
("pode", "por favor", "obrigado") em volta. "Responde a template nosso" = cita (`context.id`) um template
outbound do remetente, de qualquer data, ou há template ao telefone nos últimos 7 dias.

- **Pedido do titular (LGPD)**: "apague/excluam meus dados", "excluir meu cadastro", "acesso aos meus
  dados", "lgpd" → supressão `tudo` + item em Conversas com prazo de 15 dias + `contact_events`.
- **Parar em qualquer conversa, a qualquer tempo**: mensagem inteira "pare", "parar", "stop",
  "descadastrar", "sair da lista", "parar promoções", "cancelar inscrição"...; frase com a mensagem/contato/
  lista/oferta como objeto ("para de me mandar mensagem", "não me manda mais nada", "me exclua da lista").
- **Parar em resposta a template nosso**: "não quero", "não tenho interesse", "cancelar", "chega",
  "bloquear", "denunciar", "sair", "remover"... (`denunciar` vira motivo `denunciou`, irreversível).
- **"Agora não"** em resposta a template: "não", "agora não", "prefiro não", "não preciso", "depois"...
  → destinatário `respondido`; a MARKETING, recusa registrada (carência 90 d; 2 = supressão de marketing).
- **Opt-in**: botão "Quero receber"/"Sim, quero"/"Aceito"... num template nosso registra consentimento
  (`whatsapp_optin`) - é o caminho para a base compartilhada: o primeiro contato pede permissão.
- Qualquer outra resposta: `respondido`, `ultima_resposta_em`, a régua sai (ou segue ao passo de texto) e a
  mensagem aparece em **Conversas** até alguém tratar.
- Frases do atendimento ("não quero receber pelo Pix", "descadastrar o cartão") **não** bloqueiam.
- No histórico importado a mesma classificação vale, com o contexto (mensagem citada ou template nos 7
  dias anteriores); sem contexto só o inequívoco bloqueia.

## 7. Importação do histórico da Kapso `[inferido]`

Endpoints lidos na documentação pública em 28/10/2026 (não rodados contra a conta ainda):
`GET /meta/whatsapp/v24.0/{phone_number_id}/conversations?fields=kapso(default)&last_active_since=`,
`.../messages?fields=kapso(default)&since=&until=`, `.../contacts?fields=kapso(default)` (cursor
`after`, 100 por página), `GET /platform/v1/whatsapp/broadcasts` e `/{id}/recipients`, `GET
/platform/v1/whatsapp/contacts/{telefone}/marketing_preferences`. **`kapso()` vazio omite os campos da
Kapso**; é `kapso(default)` `[revisão]`. Limite 1000 req/min no plano Pro (`X-RateLimit-Remaining`;
429 com `Retry-After`).

`disparo:importar-historico-kapso {sender} [--todos] [--desde=] [--ate=] [--incremental] [--contatos]
[--broadcasts]`: conversas → contato (nome só se vazio; perfil em `atributos.whatsapp_profile`),
mensagens → uma linha por `wamid` sem texto (classificador com contexto em memória; supressão `historico`,
recusa, pedido LGPD), broadcasts → `external_key` `broadcast:{id}:{rid}` casado com a mensagem existente
por (telefone, template, ±30 min); `suppressed` → supressão de marketing; `error_details.code` 131026/
131049 → quarentena/carência. Primeira importação cobre 180 dias; `--incremental` parte de
`historico_importado_ate` − 1 h. Agendada todo dia às 03:00 e a cada 15 min enquanto houver campanha em
andamento. Registrar `[prod]` aqui o que de fato veio em `messages`/`conversations` na primeira rodada real.

Como o `ImportadorKapso` faz de fato `[código]` (o formato das respostas da Kapso segue `[inferido]`: a
leitura dos campos é tolerante e a primeira importação real deve anotar aqui o que veio):
- Janela: `--desde/--ate` explícitos; `--incremental` parte de `historico_importado_ate` menos 60 min
  (`historico.folga_incremental_minutos`); sem nada, `historico.janela_inicial_dias` (180). O cursor nunca volta.
- **Texto nunca é gravado** (`conteudo` nulo em `historico_kapso`): a inbound passa em memória pelo
  `ClassificadorDeResposta::classificar` COMPLETO, com contexto - `respondeATemplate` = o `context.id` aponta
  para template outbound do mesmo remetente OU houve template outbound ao telefone nos 7 dias ANTERIORES à
  inbound (`historico.resposta_janela_dias`). Outbound é importado antes de inbound por isso.
  PARAR → supressão `historico` (origem `historico_kapso`, wamid, criada no momento da resposta);
  PEDIDO_LGPD → idem + `contact_events.pedido_lgpd`; NAO_AGORA a template MARKETING (categoria do payload ou
  do cache de templates) → `registrarRecusa` com a data da resposta (recusa antiga não rejuvenesce a carência).
  Nada é reclassificado em importação repetida (só mensagem nova classifica).
- Conversas: criam/atualizam o contato (origem kapso, tipo outro), `kapso_conversation_id`, `kapso_contact_id`,
  `ultima_resposta_em`/`ultimo_envio_em` (só avançam). `--contatos` só completa quem já existe (a lista inclui
  o atendimento inteiro). Mensagem que já estava na trilha (saiu daqui) tem a entrega reconciliada (só avança:
  `pending`/`enviado_incerto` vira o que a Kapso diz).
- Broadcasts: cada destinatário vira outbound `broadcast_kapso` com `external_key = broadcast:{id}:{rid}`,
  casado com mensagem existente por (telefone, template, ±30 min) para não contar em dobro; `suppressed` →
  supressão `meta_marketing_stopped` escopo marketing do remetente; `error_details.code` 131026 →
  `sem_whatsapp_em` do evento, 131049 → carência contada do evento. Broadcast fora da janela é pulado.
- Rate limit: `KapsoClient::rateLimitRestante()` < 20 → espera 15 s antes da próxima página; 429 → espera o
  `Retry-After` e repete até 3 vezes. Resultado em `contact_imports` (tipo kapso, `resultado` com mensagens,
  contatos, classificações e supressões por motivo); o comando imprime o relatório sem PII.

Preferências de marketing (`GET /platform/v1/whatsapp/contacts/{telefone}/marketing_preferences`) são
consultadas uma a uma, então só sob demanda: ação "Conferir preferências na Meta" no público de uma
campanha MARKETING e no detalhe do contato; `stopped` vira supressão `meta_marketing_stopped` de escopo
`marketing` naquele remetente.

## 8. Comandos e agenda `[código]`

| Comando | Agenda (America/Sao_Paulo) | O que faz |
|---|---|---|
| `disparo:executar-campanhas` | a cada minuto, `withoutOverlapping(5)` | uma rodada por remetente das campanhas em andamento (ou aprovadas com horário vencido), por prioridade; orçamento de 50 s |
| `disparo:processar-reguas` | a cada 10 min, `withoutOverlapping(30)`; `--segmentos` às 09:30 | passos vencidos das inscrições; reavaliação dos gatilhos por segmento |
| `disparo:sincronizar-remetentes` | de hora em hora; `--rapido` a cada 10 min com campanha rodando | qualidade/tier/saúde → snapshot; RED bloqueia, YELLOW pausa MARKETING, volta a GREEN reinicia o aquecimento; Discord |
| `disparo:sincronizar-templates` | a cada 6 h | cache; categoria que mudou alerta |
| `disparo:importar-historico-kapso --todos` | 03:00; `--incremental` a cada 15 min com campanha rodando | seção 7 |
| `disparo:importar-csv`, `disparo:importar-sellers-inativos` | manual | bases |
| `disparo:registrar-webhook {sender}` | manual | gera o segredo (mostra uma vez), cadastra na Kapso |
| `disparo:registrar-numero {sender}` | manual | provider Meta: request_code / verify_code / register |
| `disparo:diagnostico` | manual | batimentos (agendador por minuto, worker a cada 5 min), interruptor, pepper, remetentes e webhooks na Kapso, webhooks sem processar |
| `disparo:podar` | 04:00 | retenção (seção 2.13) |

## 9. Sellers inativos (unico-api) `[código]` `[decisão]` `[inferido]`

O que existe `[código]` (`ImportadorSellersInativos`, `UnicopagClient`): a fonte é um contrato JSON (lista de
`{account_id, telefone?, nome?, email?, ultima_venda_em?, dias_sem_venda?, etapa?, aprovado_em?}`), de
`UNICOPAG_SELLERS_ENDPOINT` (`{dias}` substituído, `links.next` seguido) ou de `--arquivo`. Telefone faltando:
`GET register` por conta, com chave própria do Disparo; só telefone e nome normalizados vão para o cache (24 h).
Sem telefone em lugar nenhum a conta é rejeitada (erros + rejeitadas.csv com o account_id) e **nunca vira
contato**. Contato: origem unicopag, tipo seller_inativo, `account_id`, atributos `ultima_venda_em`,
`dias_sem_venda`, `etapa`, `data_cadastro` (as variáveis do template de recuperação), consentimento
`cliente_ativo`, base "Sellers inativos" (`sellers-inativos`, criada se faltar). **Base viva**: venda mais
recente que `--dias` ou etapa `vendeu` → tipo seller_ativo, tag `voltou_a_vender`, sai da base, inscrição
ativa em régua ganha `convertido_em/conversao_origem=venda` e encerra `converteu` (se `sair_ao_converter`),
destinatários de campanha com envio nos 30 dias antes da venda ganham a conversão. Quem volta a ficar inativo
reentra na base sem a tag.

O usuário só de leitura no RDS que o KYC pediu em 18/10 **não existe** (as aplicações não têm CREATE USER
nem GRANT; KB do KYC 8.23) e a rota de cadastro `GET /api/kyc-panel/accounts/{id}/register` responde 401 à
chave do MED. Logo a fonte **não é** um GRANT por coluna `[revisão]`. Contrato adotado: lista JSON
`{account_id, telefone?, nome?, email?, ultima_venda_em?, dias_sem_venda?, etapa?, aprovado_em?}` vinda de
arquivo (`--arquivo=`) ou de `UNICOPAG_SELLERS_ENDPOINT` (a construir no unico-api ou no KYC, que já calcula
etapa e última venda por conta de hora em hora - rota com X-API-Key no padrão de `/api/med/stores`).
Telefone faltando: `GET register` com chave **própria** do Disparo, cache 24 h só do normalizado; sem
telefone = rejeitado (CSV para o comercial completar), nunca contato. **Base viva**: quem vende sai da base
"Sellers inativos", ganha `tipo=seller_ativo` e a tag `voltou_a_vender`, a inscrição na régua sai com
`converteu` e o destinatário de campanha com envio nos 30 dias anteriores ganha `convertido_em`.
Campanha/régua com `objetivo=ativar` não deve alcançar conta aprovada há menos de 90 dias (a régua de
ativação do KYC cobre esse período) até existir consulta ao KYC - regra operacional, ainda não guarda.

## 10. Webhooks `[código]`

- `POST /api/webhooks/kapso/{webhook_key}` (throttle 600/min): remetente pela chave aleatória da URL
  (desconhecida = 404); HMAC-SHA256 do corpo cru com o segredo do remetente em `X-Webhook-Signature`
  (sem segredo ou inválida = 401); evento em `X-Webhook-Event`; `batch=true` processado payload a payload;
  `phone_number_id` do corpo diferente do remetente = 202 ignorado; só `kapso.direction=inbound` conta
  como resposta; `from` canonizado com o nono dígito antes do hash; processamento **inline** (o opt-out
  fica gravado antes do 200, sem depender de worker); dedupe por `X-Idempotency-Key` e, no conteúdo, por
  wamid+status (só avança) e wamid único de inbound; `webhook_calls` só com resumo.
- `POST /api/webhooks/meta`: `GET` responde `hub.challenge` (verify token com `hash_equals`); `POST` com
  `X-Hub-Signature-256` (App Secret); `metadata.phone_number_id` acha o remetente `meta` (desconhecido =
  200 sem persistir); `statuses`, `messages` e `user_preferences` viram o mesmo evento interno.
- Eventos tratados: `whatsapp.message.sent|delivered|read|failed`, `whatsapp.message.received`,
  `whatsapp.contact.marketing_preference_changed` (Kapso) e `user_preferences` (Meta).
- Status de **wamid desconhecido** (painel da Kapso, KYC pelo mesmo número) vira linha outbound
  `origem=webhook` com o telefone do destinatário: entra no teto e na conversa aberta `[revisão]`.
- O envio grava a mensagem `pending` **antes** do POST; se o webhook chegou antes do wamid ser gravado e
  criou a linha, o envio adota essa linha (vínculo com campanha/régua) e descarta a sua `[revisão]`.

## 11. API `/api/v1` (X-API-Key = `API_KEY`) `[código]`

`GET /health`; `GET /suppressions?since=` e `POST /suppressions` (contrato com o KYC: hash, escopo, motivo,
origem); `GET /senders/{id}/templates`; `GET /contacts/{telefone}` (mascarado); `POST /campaigns` (só
rascunho) e `GET /campaigns/{id}`. Aprovação, início, pausa e cancelamento **só no painel**: a API não tem
usuário humano para o quatro olhos.

## 12. Painel `[código]`

Grupos: **Contatos** (Contatos, Bases, Tags, Segmentos, Importações, Bloqueios, Conversas), **Disparos**
(Campanhas, Réguas), **Configuração** (Remetentes, Templates), **Sistema** (Usuários). Dashboard:
qualidade e teto de cada remetente com o usado nas 24 h por origem, envios/entregas/respostas/opt-outs
de hoje e 7 dias, campanhas em andamento, respostas sem tratamento, bloqueios da semana, banner de
SIMULAÇÃO e aviso de histórico desatualizado.

Permissões (`App\Support\Permissions`): `view contacts`, `manage contacts`, `import contacts`,
`view conversations`, `view pii`, `anonymize contacts`, `release suppressions`, `view campaigns`,
`manage campaigns`, `approve campaigns` (aprovar, iniciar, retomar campanha e régua), `manage journeys`,
`manage senders` (remetentes, templates, webhooks, histórico da Kapso, sellers do unico-api, religar),
`manage users`. Papéis: Super Admin (tudo), Admin (tudo menos usuários), Operador (contatos, CSV,
campanhas e réguas sem aprovar, conversas; sem PII, sem remetentes), Visualizador (só ver). Toda ação só
aparece com `Permissions::allows(...)` e é checada de novo no servidor; o Filament recusa chamar ação
escondida; `status` das campanhas e réguas não é campo de formulário.

## 13. Em aberto

- **Dono do número comercial**: decidir entre (A) o KYC mandar os avisos pela `/api/v1` desta plataforma e
  (B) manter a transição de 2.15. Enquanto (B): o KYC passa a consultar `GET /api/v1/suppressions` antes
  de cada aviso e a postar os pedidos para parar que colhe (`whatsapp_telefones_bloqueados`,
  `pediu_para_parar_em`).
- Rota de lista de sellers no unico-api ou no KYC (seção 9).
- Número para as ofertas da UnicoDrop: o comercial (RED em 26/10) ou um número novo aquecido do zero - e
  em qual portfólio da Meta (o limite é por portfólio) `[prod a mapear]`.
- Templates MARKETING novos para as bases compartilhadas, com opt-out claro no texto e o canal do
  encarregado; template de **permissão** ("Quero receber" / "Não quero") como primeiro contato da base
  compartilhada.
- MCP como cliente da `/api/v1`.
- Primeira importação real do histórico da Kapso: anotar `[prod]` campos, volumes e retenção do projeto.
- Mapear `[prod]` número → WABA → portfólio dos três números (o +1 203 do KYC pode estar no portfólio da
  Kapso).

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

### 2026-10-28 - Réguas rodando, disjuntor compartilhado, suíte do núcleo
`ProcessadorDeReguas` (4.9.1), `Disjuntor` extraído do executor (5), observers de base/tag, `passos_pulados`
e `ultimo_motivo` na inscrição (migration 000013), `JourneyEnrollment::converter`. Guardas: intervalo mínimo
da régua por `intervalo_minimo_horas` (estrito), histórico em lote para a prévia, e correção de precedência
que deixava `sem_consentimento` morto para contato de origem externa quando a campanha informava
`exige_consentimento=false`. `whatsapp_messages.user_id` entrou no `$fillable` (o texto do painel estourava).
Suíte: guardas (cada motivo), teto efetivo, supressões, executor (as classes de recusa, tranche, orçamento,
janela), despachante, processador de eventos direto e réguas.

### 2026-10-28 - Frente A: webhooks, API v1, comandos e agenda
Implementados conforme as seções 8.1 e 10.1 (testes em `tests/Feature/Webhooks`, `Api` e `Console`). Correções
em relação ao desenho original: processamento do webhook inline (não na fila), idempotência com
`insertOrIgnore` e `processado_em`, chave aleatória na URL em vez do id do remetente, segredo por remetente sem
fallback de `.env`, `pricing.category` do status, wamid desconhecido contando no teto.

### 2026-10-28 - Importação, histórico da Kapso, sellers inativos, anonimização e retenção (frente B)
`ImportadorCsv` (prévia, denylist, rejeitadas.csv, precedência do nome), `ImportadorKapso` (texto só em
memória, classificação com contexto, broadcasts casados, rate limit), `ImportadorSellersInativos` (base
viva, conversão), `AnonimizarContato` (telefone vira `anon:<hash12>` em todas as tabelas, supressão pelo
hash gravada ANTES de trocar o telefone, do payload sobra só a categoria) e `ExportarDadosDoTitular`
(direito de acesso), `Retencao::executar()` (conteúdo/payload/variáveis > 180 dias, lead externo parado >
12 meses anonimizado, failed_jobs > 7 dias, webhook_calls > 30 dias, arquivo de importação). Colunas novas:
`contacts.nome_origem`, `contacts.kapso_conversation_id`, `contact_imports.sem_consentimento`,
`contact_imports.resultado` (migration 000014). Decisão registrada: nome sem letra (só emoji) nunca vira
nome; o nome de planilha tem sempre precedência `csv`. A agenda (03:00 e incremental a cada 15 min com campanha em andamento) e a chamada de `Retencao` em
`disparo:podar` vieram com a frente A.

### 2026-10-28 - Nascimento da plataforma
Repositório reorganizado: a CLI/MCP em TypeScript foi para `mcp/` (envio real desligado); a raiz passa a
ser a aplicação Laravel com painel Filament. Decisões da seção 2 registradas a partir do incidente do
número comercial (KB do KYC 8.23) e do pedido do operador de 28/10/2026 ("ferramenta de disparos para
recuperação e ativação de sellers; bases compartilhadas com a UnicoDrop; base de sellers inativos; gerir e
nutrir esses contatos"; "na Kapso possui todo o histórico").

### 2026-10-28 - Revisão adversarial do desenho (4 lentes, 60 achados)
Antes de escrever o painel, o desenho passou por quatro críticos independentes (política da Meta e
qualidade do número; operação; engenharia e consistência com KYC/MED; LGPD e segurança). O que mudou:
interruptor global deixou de ser guarda (prévia cega); tranches com disjuntor de engajamento; três classes
de recusa (qualidade sem sonda, conta impedida com sonda a cada 120 min, transitória); teto amarrado ao
tier e ao que sai por fora pelo webhook; 131049 = carência; frequência de MARKETING global; consentimento
estruturado e guarda de MARKETING a origem externa; público congelado na aprovação com hash; auto-aprovação
com espera e teto (fim da exceção do Super Admin); réguas com aprovação; HMAC com pepper; anonimização
completa; webhook com chave aleatória por remetente, processamento inline e dedupe por conteúdo; histórico
da Kapso só com metadados e classificador com contexto; `kapso(default)`; scrubber de PII em erro e
Discord; MCP sem envio real; `view conversations` separado de `view pii`; pedido LGPD como classe de
resposta; retenção.
