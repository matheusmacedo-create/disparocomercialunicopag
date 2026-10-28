#!/usr/bin/env node
import { randomUUID } from "node:crypto";
import { resolve } from "node:path";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { carregarConfig } from "./config.js";
import { WhatsAppClient } from "./whatsapp.js";
import { carregarConfigPlataforma, PlataformaClient } from "./plataforma.js";
import { normalizarTelefone } from "./phone.js";
import {
  executarDisparo,
  lerContatosCsv,
  montarComponentes,
  type Contato,
  type ModeloDisparo,
  type ProgressoDisparo,
} from "./dispatch.js";

const config = carregarConfig();
const client = new WhatsAppClient(config);
const plataforma = new PlataformaClient(carregarConfigPlataforma());

// A plataforma Laravel (raiz do repositório) é o canal de envio: aplica supressões, teto, janela,
// aprovação e trilha. Este MCP fala direto com a Meta e NÃO vê nada disso, então o envio real fica
// desligado por padrão - só com MCP_ENVIO_REAL=true no ambiente, para emergência conhecida.
const ENVIO_REAL_PERMITIDO = process.env.MCP_ENVIO_REAL === "true";
const envioRealBloqueado = () =>
  falha(new Error("Envio real desligado neste MCP: use a plataforma de disparos (painel /admin). Para emergência, defina MCP_ENVIO_REAL=true."));

const server = new McpServer({ name: "unicopag-whatsapp", version: "1.0.0" });

const json = (dados: unknown) => ({ content: [{ type: "text" as const, text: JSON.stringify(dados, null, 2) }] });
const falha = (e: unknown) => ({ isError: true, content: [{ type: "text" as const, text: (e as Error).message }] });

const modeloSchema = {
  template: z.string().describe("Nome do template aprovado na Meta"),
  idioma: z.string().default("pt_BR").describe("Código de idioma do template"),
  variaveis_corpo: z
    .array(z.string())
    .optional()
    .describe('Valores de {{1}}, {{2}}... Aceita "{{coluna}}" ou "{{coluna|padrão}}", ex.: ["{{first_name|tudo bem}}"]'),
  variaveis_nomeadas: z
    .record(z.string(), z.string())
    .optional()
    .describe("Para templates com variáveis nomeadas: { nome_variavel: valor }"),
  header_texto: z.string().optional(),
  header_imagem_url: z.string().url().optional(),
  header_documento_url: z.string().url().optional(),
  header_video_url: z.string().url().optional(),
  botao_url_sufixo: z.string().optional().describe("Parte dinâmica do botão de URL"),
};

function paraModelo(a: {
  template: string;
  idioma: string;
  variaveis_corpo?: string[];
  variaveis_nomeadas?: Record<string, string>;
  header_texto?: string;
  header_imagem_url?: string;
  header_documento_url?: string;
  header_video_url?: string;
  botao_url_sufixo?: string;
}): ModeloDisparo {
  return {
    template: a.template,
    idioma: a.idioma,
    variaveisCorpo: a.variaveis_corpo,
    variaveisNomeadas: a.variaveis_nomeadas,
    headerTexto: a.header_texto,
    headerImagemUrl: a.header_imagem_url,
    headerDocumentoUrl: a.header_documento_url,
    headerVideoUrl: a.header_video_url,
    botaoUrlSufixo: a.botao_url_sufixo,
  };
}

server.registerTool(
  "whatsapp_info_numero",
  {
    title: "Status do número Unicopag",
    description: "Mostra nome verificado, qualidade (GREEN/YELLOW/RED) e limite diário de conversas do número de WhatsApp da Unicopag.",
    inputSchema: {},
  },
  async () => {
    try {
      return json(await client.infoNumero());
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "whatsapp_registrar_numero",
  {
    title: "Registrar número na Cloud API",
    description:
      "Registra o número da Unicopag na Cloud API (necessário quando whatsapp_info_numero mostra status DISCONNECTED). " +
      "etapa=solicitar_codigo envia SMS/ligação; etapa=verificar_codigo confirma o código; etapa=registrar ativa o número com um PIN de 6 dígitos. " +
      "ATENÇÃO: se o número estiver em uso no app WhatsApp/WhatsApp Business do celular, o app será desconectado.",
    inputSchema: {
      etapa: z.enum(["solicitar_codigo", "verificar_codigo", "registrar"]),
      metodo: z.enum(["SMS", "VOICE"]).default("SMS"),
      codigo: z.string().regex(/^\d{6}$/).optional().describe("Código recebido (etapa verificar_codigo)"),
      pin: z.string().regex(/^\d{6}$/).optional().describe("PIN de 6 dígitos da verificação em duas etapas (etapa registrar)"),
    },
  },
  async ({ etapa, metodo, codigo, pin }) => {
    try {
      if (etapa === "solicitar_codigo") return json(await client.solicitarCodigo(metodo));
      if (etapa === "verificar_codigo") {
        if (!codigo) throw new Error("Informe o código de 6 dígitos recebido");
        return json(await client.verificarCodigo(codigo));
      }
      if (!pin) throw new Error("Informe um PIN de 6 dígitos");
      return json(await client.registrarNumero(pin));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "whatsapp_listar_templates",
  {
    title: "Listar templates",
    description: "Lista os templates de mensagem da conta WhatsApp Business (WABA), com status e variáveis.",
    inputSchema: {
      status: z.enum(["APPROVED", "PENDING", "REJECTED", "PAUSED", "DISABLED"]).optional().describe("Filtra por status"),
    },
  },
  async ({ status }) => {
    try {
      return json(await client.listarTemplates(status));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "whatsapp_enviar_template",
  {
    title: "Enviar template para um número",
    description: "Envia um template aprovado para um único número. Use para testar o template antes do disparo em massa.",
    inputSchema: {
      telefone: z.string().describe("Telefone do destinatário (qualquer formato; DDI 55 é aplicado se faltar)"),
      dados_contato: z.record(z.string(), z.string()).optional().describe('Dados para preencher variáveis, ex.: {"first_name":"Ana"}'),
      ...modeloSchema,
    },
  },
  async (args) => {
    try {
      if (!ENVIO_REAL_PERMITIDO) return envioRealBloqueado();
      const telefone = normalizarTelefone(args.telefone, config.ddiPadrao);
      if (!telefone) throw new Error(`Telefone inválido: ${args.telefone}`);
      const modelo = paraModelo(args);
      const componentes = montarComponentes(modelo, args.dados_contato ?? {});
      return json(await client.enviarTemplate(telefone, modelo.template, args.idioma, componentes));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "whatsapp_enviar_texto",
  {
    title: "Enviar texto livre",
    description:
      "Envia texto livre. ATENÇÃO: a Meta só entrega se o contato mandou mensagem para a Unicopag nas últimas 24h. Para prospecção use templates.",
    inputSchema: {
      telefone: z.string(),
      texto: z.string().min(1).max(4096),
      preview_url: z.boolean().default(false),
    },
  },
  async ({ telefone, texto, preview_url }) => {
    try {
      if (!ENVIO_REAL_PERMITIDO) return envioRealBloqueado();
      const numero = normalizarTelefone(telefone, config.ddiPadrao);
      if (!numero) throw new Error(`Telefone inválido: ${telefone}`);
      return json(await client.enviarTexto(numero, texto, preview_url));
    } catch (e) {
      return falha(e);
    }
  },
);

interface Job {
  id: string;
  iniciadoEm: string;
  finalizadoEm?: string;
  dryRun: boolean;
  template: string;
  progresso: ProgressoDisparo;
  erro?: string;
  controle: AbortController;
}
const jobs = new Map<string, Job>();

server.registerTool(
  "whatsapp_disparo_em_massa",
  {
    title: "Disparo comercial em massa",
    description:
      "Dispara um template aprovado para uma lista de contatos (CSV ou lista inline). Roda em segundo plano e retorna um job_id; " +
      "acompanhe com whatsapp_status_disparo. Por padrão é SIMULAÇÃO (dry_run=true): rode primeiro assim, mostre o resumo ao usuário " +
      "e só então repita com dry_run=false após confirmação explícita. Remove duplicados, números inválidos e quem está em data/optout.txt.",
    inputSchema: {
      arquivo_csv: z.string().optional().describe("Caminho do CSV (ex.: data/contatos.csv). Colunas: phone, first_name, ..."),
      contatos: z.array(z.record(z.string(), z.string())).optional().describe("Alternativa ao CSV: lista de contatos"),
      coluna_telefone: z.string().optional().describe("Nome da coluna de telefone (detecta phone/telefone/whatsapp automaticamente)"),
      dry_run: z.boolean().default(true).describe("true = só simula, não envia nada"),
      ...modeloSchema,
    },
  },
  async (args) => {
    try {
      if (!args.dry_run && !ENVIO_REAL_PERMITIDO) return envioRealBloqueado();
      let contatos: Contato[];
      if (args.arquivo_csv) contatos = lerContatosCsv(resolve(args.arquivo_csv));
      else if (args.contatos?.length) contatos = args.contatos;
      else throw new Error("Informe arquivo_csv ou contatos");

      const job: Job = {
        id: randomUUID().slice(0, 8),
        iniciadoEm: new Date().toISOString(),
        dryRun: args.dry_run,
        template: args.template,
        progresso: { total: contatos.length, processados: 0, enviados: 0, erros: 0, ignorados: 0 },
        controle: new AbortController(),
      };
      jobs.set(job.id, job);

      executarDisparo(client, config, {
        modelo: paraModelo(args),
        contatos,
        colunaTelefone: args.coluna_telefone,
        dryRun: args.dry_run,
        sinalCancelamento: job.controle.signal,
        aoProgredir: (p) => (job.progresso = p),
      })
        .then((p) => (job.progresso = p))
        .catch((e) => (job.erro = (e as Error).message))
        .finally(() => (job.finalizadoEm = new Date().toISOString()));

      return json({
        job_id: job.id,
        total_contatos: contatos.length,
        modo: args.dry_run ? "SIMULAÇÃO (nada será enviado)" : "ENVIO REAL",
        limite_por_execucao: config.limitePorExecucao,
        velocidade: `${config.msgPorSegundo} msg/s`,
      });
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "whatsapp_status_disparo",
  {
    title: "Status do disparo",
    description: "Mostra o progresso de um disparo (ou de todos, se job_id for omitido).",
    inputSchema: { job_id: z.string().optional() },
  },
  async ({ job_id }) => {
    const resumo = ({ controle, ...j }: Job) => ({ ...j, em_andamento: !j.finalizadoEm });
    if (job_id) {
      const job = jobs.get(job_id);
      return job ? json(resumo(job)) : falha(new Error(`Job ${job_id} não encontrado`));
    }
    return json([...jobs.values()].map(resumo));
  },
);

server.registerTool(
  "whatsapp_cancelar_disparo",
  {
    title: "Cancelar disparo",
    description: "Interrompe um disparo em andamento (mensagens já enviadas não são desfeitas).",
    inputSchema: { job_id: z.string() },
  },
  async ({ job_id }) => {
    const job = jobs.get(job_id);
    if (!job) return falha(new Error(`Job ${job_id} não encontrado`));
    job.controle.abort();
    return json({ job_id, cancelamento: "solicitado" });
  },
);


// ---------------------------------------------------------------------------------------------
// Plataforma de disparos (/api/v1): é por aqui que se gere e nutre a base. Nada de envio direto.
// ---------------------------------------------------------------------------------------------

server.registerTool(
  "disparo_saude",
  {
    title: "Saúde da plataforma de disparos",
    description: "Confere a API da plataforma (/api/v1/health): interruptor de envio, remetentes e campanhas em andamento.",
    inputSchema: {},
  },
  async () => {
    try {
      return json(await plataforma.saude());
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_consultar_contato",
  {
    title: "Consultar contato na plataforma",
    description: "Situação de um telefone na base: status (ativo, pediu para parar, sem WhatsApp), consentimento, último envio e última resposta. Telefone mascarado.",
    inputSchema: { telefone: z.string().describe("Telefone em qualquer formato (DDI 55 é aplicado se faltar)") },
  },
  async ({ telefone }) => {
    try {
      const numero = normalizarTelefone(telefone, config.ddiPadrao);
      if (!numero) throw new Error(`Telefone inválido: ${telefone}`);
      return json(await plataforma.consultarContato(numero));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_bloquear_telefone",
  {
    title: "Bloquear telefone (opt-out)",
    description:
      "Registra na plataforma que o telefone NÃO deve mais receber mensagens: escopo 'tudo' (para sempre, em todos os remetentes) ou 'marketing' (só ofertas). Use quando o cliente pediu para parar por outro canal.",
    inputSchema: {
      telefone: z.string(),
      escopo: z.enum(["tudo", "marketing"]).default("tudo"),
      motivo: z.string().min(3).max(50).default("manual").describe("Ex.: manual, importacao"),
      detalhes: z.string().max(500).optional().describe("De onde veio o pedido (sem dados pessoais de terceiros)"),
    },
  },
  async ({ telefone, escopo, motivo, detalhes }) => {
    try {
      const numero = normalizarTelefone(telefone, config.ddiPadrao);
      if (!numero) throw new Error(`Telefone inválido: ${telefone}`);
      return json(await plataforma.bloquear(numero, escopo, motivo, detalhes));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_listar_bloqueios",
  {
    title: "Listar bloqueios",
    description: "Supressões (pedidos para parar, denúncias, preferência de marketing) registradas na plataforma desde uma data.",
    inputSchema: { desde: z.string().optional().describe("ISO 8601, ex.: 2026-10-01T00:00:00Z") },
  },
  async ({ desde }) => {
    try {
      return json(await plataforma.listarBloqueios(desde));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_listar_templates_remetente",
  {
    title: "Templates de um remetente da plataforma",
    description: "Templates (cache da Meta) de um remetente cadastrado na plataforma, com categoria e status.",
    inputSchema: { remetente_id: z.number().int().positive() },
  },
  async ({ remetente_id }) => {
    try {
      return json(await plataforma.listarTemplates(remetente_id));
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_criar_campanha_rascunho",
  {
    title: "Criar campanha em rascunho",
    description:
      "Cria uma campanha em RASCUNHO na plataforma. Nada sai daqui: a prévia, a aprovação por outra pessoa e o início acontecem no painel (/admin). " +
      "Objetivo: recuperar | ativar | ofertar | avisar. Público: ids de bases, segmento ou campanha anterior.",
    inputSchema: {
      nome: z.string().min(3).max(120),
      remetente_id: z.number().int().positive(),
      objetivo: z.enum(["recuperar", "ativar", "ofertar", "avisar"]).default("recuperar"),
      template: z.string().describe("Nome do template aprovado na Meta"),
      idioma: z.string().default("pt_BR"),
      variaveis_corpo: z.array(z.string()).optional().describe('Valores de {{1}}, {{2}}... como "{{primeiro_nome|tudo bem}}"'),
      listas: z.array(z.number().int()).optional().describe("Ids das bases"),
      segmento_id: z.number().int().optional(),
      observacoes: z.string().max(1000).optional(),
    },
  },
  async (args) => {
    try {
      return json(
        await plataforma.criarCampanhaRascunho({
          nome: args.nome,
          sender_id: args.remetente_id,
          objetivo: args.objetivo,
          template_nome: args.template,
          template_idioma: args.idioma,
          variaveis: args.variaveis_corpo ? { corpo: args.variaveis_corpo } : undefined,
          publico: { listas: args.listas ?? [], segment_id: args.segmento_id },
          observacoes: args.observacoes,
        }),
      );
    } catch (e) {
      return falha(e);
    }
  },
);

server.registerTool(
  "disparo_status_campanha",
  {
    title: "Status de uma campanha",
    description: "Situação, totais (enviados, entregues, lidos, respondidos, falhas, pulados) e prévia de uma campanha da plataforma.",
    inputSchema: { campanha_id: z.number().int().positive() },
  },
  async ({ campanha_id }) => {
    try {
      return json(await plataforma.statusCampanha(campanha_id));
    } catch (e) {
      return falha(e);
    }
  },
);

await server.connect(new StdioServerTransport());
console.error("MCP unicopag-whatsapp pronto (stdio)");
