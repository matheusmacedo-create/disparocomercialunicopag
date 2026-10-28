<?php

/*
 * Regras da plataforma de disparos (KB doc/DISPARO_KNOWLEDGE_BASE.md, seções 2 e 5). Tudo o que vira
 * decisão de "mandar ou não" lê daqui; os remetentes refinam por número (teto, ritmo, janela).
 */
return [
    // Simulação por padrão: false = nenhuma mensagem sai, a campanha roda inteira como prévia executada (KB 2.1).
    'envio_habilitado' => (bool) env('DISPARO_ENVIO_HABILITADO', false),

    'painel_locale' => 'pt_BR',

    'fuso' => env('DISPARO_FUSO', 'America/Sao_Paulo'),

    // Segredo do HMAC dos hashes de telefone e CPF/CNPJ (supressão, dedupe, trilha por contato). Não é o
    // APP_KEY (key:generate o trocaria e todo hash deixaria de casar) e nunca roda. Produção não sobe sem ele.
    'hash_pepper' => env('DISPARO_HASH_PEPPER'),

    // X-API-Key da API v1 (/api/v1: MCP, KYC lendo e gravando supressões). Vazio = API desligada (503).
    'api_key' => env('API_KEY'),

    // Teto que nenhum remetente ultrapassa, mesmo editado no painel (o incidente foi 650 num dia).
    'teto_maximo_absoluto' => (int) env('DISPARO_TETO_MAXIMO_ABSOLUTO', 1000),

    // Operação solo: quem criou aprova a própria campanha só com confirmação separada, espera obrigatória e
    // teto por campanha; acima disso exige um segundo usuário (KB 2.2).
    'auto_aprovacao' => [
        'espera_minutos' => (int) env('DISPARO_AUTO_APROVACAO_ESPERA_MIN', 30),
        'teto_destinatarios' => (int) env('DISPARO_AUTO_APROVACAO_TETO', 200),
    ],

    // Enviar teste: só para o telefone verificado do próprio usuário, poucas vezes por dia.
    'teste' => [
        'max_por_dia' => 5,
    ],

    'remetente' => [
        // Padrões de um remetente novo (editáveis em Remetentes).
        'teto_diario' => (int) env('DISPARO_TETO_DIARIO', 200),
        'msgs_por_minuto' => (int) env('DISPARO_MSGS_POR_MINUTO', 20),
        'janela_inicio' => env('DISPARO_JANELA_INICIO', '08:00'),
        'janela_fim' => env('DISPARO_JANELA_FIM', '20:00'),
        // Aquecimento: teto do primeiro dia e fator diário até o teto configurado (KB 2.11).
        'aquecimento_teto_inicial' => (int) env('DISPARO_AQUECIMENTO_TETO_INICIAL', 50),
        'aquecimento_fator' => (float) env('DISPARO_AQUECIMENTO_FATOR', 1.5),
        // Qualidade YELLOW corta o teto por este fator; RED trava.
        'fator_yellow' => 0.5,
    ],

    'guardas' => [
        // Template do mesmo remetente ao telefone nos últimos N dias = conversa aberta (KB 2.8).
        'conversa_aberta_dias' => (int) env('DISPARO_CONVERSA_ABERTA_DIAS', 7),
        // Campanha UTILITY: máximo de templates por contato por remetente em 30 dias (KB 2.9).
        'frequencia_max' => (int) env('DISPARO_FREQUENCIA_MAX_30D', 2),
        'frequencia_janela_dias' => 30,
        // MARKETING: por telefone, em TODOS os remetentes (a mesma empresa): 1 a cada 30 dias, 3 a cada 90.
        'marketing_max_30d' => (int) env('DISPARO_MARKETING_MAX_30D', 1),
        'marketing_max_90d' => (int) env('DISPARO_MARKETING_MAX_90D', 3),
        // Régua: intervalo mínimo entre templates da régua e total (qualquer origem) em 30 dias.
        'regua_min_dias' => (int) env('DISPARO_REGUA_MIN_DIAS', 5),
        'regua_max_30d' => (int) env('DISPARO_REGUA_MAX_30D', 4),
        // 131026 (sem WhatsApp): quarentena.
        'sem_whatsapp_dias' => (int) env('DISPARO_SEM_WHATSAPP_DIAS', 90),
        // 131049 (a Meta limitou marketing ao usuário): carência.
        'marketing_carencia_dias' => 7,
        // "Não"/"agora não" a MARKETING: carência; duas recusas = supressão de marketing.
        'recusa_carencia_dias' => 90,
        'recusas_para_suprimir' => 2,
        // Template `sent` sem `delivered` por N horas = provável bloqueio; fora de MARKETING por M dias.
        'provavel_bloqueio_horas' => 48,
        'provavel_bloqueio_dias' => 180,
        // Falhas transitórias seguidas antes de pausar a campanha.
        'max_transitorias_seguidas' => 3,
        // Tentativas por destinatário em erro transitório (`timeout` antes do POST, 429, 5xx); mínimo 60 s entre elas.
        'max_tentativas' => 2,
        'intervalo_tentativa_segundos' => 60,
        // Dias da semana padrão de campanha MARKETING (1 = segunda ... 7 = domingo); domingo nunca.
        'dias_marketing' => [1, 2, 3, 4, 5],
    ],

    'tranches' => [
        // A primeira leva de uma campanha MARKETING é pequena; a campanha só segue depois de ler os webhooks.
        'primeira_fracao' => 0.05,
        'primeira_max' => 50,
        'espera_minutos' => (int) env('DISPARO_TRANCHE_ESPERA_MIN', 90),
    ],

    'engajamento' => [
        // Disjuntor de engajamento (janela da campanha / 24 h do remetente); amostra mínima para julgar.
        'amostra_minima' => 20,
        // `sent` sem `delivered` 30 min depois do envio (proxy de bloqueio).
        'nao_entregues_max' => 0.10,
        'nao_entregues_apos_minutos' => 30,
        // Pedidos para parar / preferência de marketing sobre os entregues.
        'optouts_max' => 0.01,
        // 131049 e 131026 sobre as tentativas.
        'carencia_131049_max' => 0.05,
        'sem_whatsapp_131026_max' => 0.05,
        // Lidos sobre entregues, 2 h depois.
        'lidos_min' => 0.30,
        'lidos_apos_minutos' => 120,
    ],

    'fila' => [
        // Réguas antes das campanhas; parte do teto fica reservada para elas.
        'reserva_reguas' => 0.30,
        // Teto de MARKETING por dia como fração do teto efetivo do remetente.
        'fracao_marketing' => 0.30,
        // Tier da Meta: a plataforma usa no máximo esta fração do limite lido da API.
        'fracao_do_tier' => 0.8,
    ],

    'custo' => [
        // Estimativa por conversa iniciada, em reais (prévia e aprovação). Ajustar à tabela da Meta/Kapso.
        'marketing' => (float) env('DISPARO_CUSTO_MARKETING', 0.40),
        'utility' => (float) env('DISPARO_CUSTO_UTILITY', 0.05),
    ],

    'lote' => [
        // Destinatários por rodada do executor (a cada minuto): fica abaixo do ritmo do remetente.
        'tamanho' => (int) env('DISPARO_LOTE', 20),
        // Orçamento de tempo da rodada (s): o executor para antes do próximo tick do agendador.
        'tempo_maximo_segundos' => 50,
        // Trava da rodada por remetente (s), bem acima da rodada: só vale para processo que morreu.
        'trava_segundos' => 600,
        // Espera (s) depois de um erro transitório do provedor: 2^n até este máximo. Os testes zeram.
        'backoff_maximo_segundos' => 10,
    ],

    'reguas' => [
        'rodada_minutos' => 10,
        'reavaliar_segmentos_as' => '09:30',
        // Orçamento de tempo da rodada das réguas (s): abaixo dos 10 min do agendador.
        'tempo_maximo_segundos' => 540,
        // Erro transitório num passo da régua: a inscrição tenta de novo depois de N minutos.
        'reagendar_transitoria_minutos' => 15,
    ],

    'historico' => [
        // Janela da importação incremental da Kapso quando o remetente nunca importou (dias).
        // Teto de páginas por consulta (proteção contra cursor que não anda).
        'max_paginas' => 5000,
        'janela_inicial_dias' => (int) env('DISPARO_HISTORICO_JANELA_INICIAL_DIAS', 180),
        'pagina' => 100,
        // Importação incremental recomeça esta folga antes de `historico_importado_ate` (status que mudou tarde).
        'folga_incremental_minutos' => 60,
        // Resposta recebida até N dias depois de um template nosso é lida como resposta a ele (KB 6).
        'resposta_janela_dias' => 7,
        // Destinatário de broadcast casa com mensagem já registrada (mesmo telefone e template) dentro desta folga.
        'broadcast_tolerancia_minutos' => 30,
        // Rate limit da Kapso (1000 req/min no Pro): com poucas requisições restantes, espera; 429 espera o Retry-After.
        'rate_limit_minimo' => 20,
        'rate_limit_espera_segundos' => 15,
        'rate_limit_tentativas' => 3,
    ],

    'importacao' => [
        // Linhas mostradas na prévia do mapeamento, antes de enfileirar.
        'previa_linhas' => 10,
        // Nomes de coluna que detectam o telefone (como a CLI original, mcp/src/dispatch.ts).
        'colunas_telefone' => ['phone', 'telefone', 'whatsapp', 'celular', 'numero', 'número', 'fone'],
        // Coluna cujo nome contém isto só pode ir para campo tipado (documento) ou ser ignorada - nunca
        // para `atributos` (que viram variável de template e ficam em claro).
        'denylist' => ['cpf', 'cnpj', 'documento', 'rg', 'endereco', 'nascimento', 'renda', 'senha'],
        // Sellers inativos: destinatário de campanha com envio até N dias antes da venda conta como conversão.
        'conversao_janela_dias' => 30,
    ],

    'provedores' => [
        'timeout' => (int) env('DISPARO_PROVEDOR_TIMEOUT', 15),
        'kapso' => [
            'base_url' => env('KAPSO_BASE_URL', 'https://api.kapso.ai'),
            'graph_version' => env('KAPSO_GRAPH_VERSION', 'v24.0'),
            // Chave de projeto usada por remetente Kapso sem chave própria.
            'api_key' => env('KAPSO_API_KEY'),
            'webhook_events' => [
                'whatsapp.message.received',
                'whatsapp.message.sent',
                'whatsapp.message.delivered',
                'whatsapp.message.read',
                'whatsapp.message.failed',
            ],
        ],
        'meta' => [
            'graph_version' => env('META_GRAPH_VERSION', 'v23.0'),
            // Token permanente de usuário do sistema, para remetente Meta sem token próprio.
            'token' => env('META_WHATSAPP_TOKEN'),
            // Webhook da Meta (App > WhatsApp > Configuration): verify token e App Secret.
            'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
            'app_secret' => env('META_APP_SECRET'),
        ],
    ],

    // Retenção (LGPD, Art. 15 e 16): o que `disparo:podar` apaga, todo dia.
    'retencao' => [
        'webhook_calls_dias' => 30,
        // Texto, payload e variáveis renderizadas das mensagens e destinatários: ficam só os metadados.
        'conteudo_dias' => (int) env('DISPARO_RETENCAO_CONTEUDO_DIAS', 180),
        // Lead de origem externa sem nenhuma interação (resposta, cadastro) por N meses é anonimizado. 0 = nunca.
        'lead_sem_interacao_meses' => (int) env('DISPARO_RETENCAO_LEAD_MESES', 12),
        'failed_jobs_dias' => 7,
        // CSV exportado da campanha pelo painel (storage/app/private/exportacoes): fica só para o download.
        'exportacoes_dias' => 7,
    ],

    // Pedido do titular (Art. 18): prazo para atender, em dias.
    'lgpd' => [
        'prazo_dias' => 15,
    ],

    'discord' => [
        'webhook_url' => env('DISCORD_DISPARO_WEBHOOK_URL'),
        'role_id' => env('DISCORD_DISPARO_ROLE_ID'),
    ],

    'unicoapi' => [
        // Cadastro do seller (telefone, nome, e-mail) pela rota do unico-api que o MED usa.
        'base_url' => env('UNICOPAG_API_BASE_URL'),
        'key' => env('UNICOPAG_API_KEY'),
        'register_endpoint' => env('UNICOPAG_REGISTER_ENDPOINT', '/api/kyc-panel/accounts/{account_id}/register'),
        // Lista de sellers inativos (contrato JSON: account_id, telefone?, nome?, email?, ultima_venda_em?,
        // dias_sem_venda?, etapa?, aprovado_em?). Caminho relativo à base ou URL absoluta; `{dias}` é substituído.
        'sellers_endpoint' => env('UNICOPAG_SELLERS_ENDPOINT'),
        // Cache do cadastro já normalizado (telefone E.164 e nome), por conta.
        'cache_cadastro_horas' => 24,
        'timeout' => 8,
    ],
];
