import { readFileSync, existsSync, mkdirSync, appendFileSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { parse } from "csv-parse/sync";
import type { Config } from "./config.js";
import { normalizarTelefone } from "./phone.js";
import { WhatsAppApiError, type ComponenteTemplate, type WhatsAppClient } from "./whatsapp.js";

export type Contato = Record<string, string>;

export interface ModeloDisparo {
  /** Nome do template aprovado na Meta */
  template: string;
  /** Código de idioma do template (ex.: pt_BR) */
  idioma?: string;
  /** Variáveis posicionais do corpo ({{1}}, {{2}}...). Aceita "{{coluna}}" e "{{coluna|padrão}}". */
  variaveisCorpo?: string[];
  /** Variáveis nomeadas do corpo, para templates com parameter_format NAMED */
  variaveisNomeadas?: Record<string, string>;
  headerTexto?: string;
  headerImagemUrl?: string;
  headerDocumentoUrl?: string;
  headerVideoUrl?: string;
  /** Sufixo dinâmico do primeiro botão de URL (índice 0) */
  botaoUrlSufixo?: string;
}

export interface OpcoesDisparo {
  modelo: ModeloDisparo;
  contatos: Contato[];
  colunaTelefone?: string;
  dryRun?: boolean;
  arquivoResultado?: string;
  arquivoOptOut?: string;
  aoProgredir?: (p: ProgressoDisparo) => void;
  sinalCancelamento?: AbortSignal;
}

export interface ResultadoContato {
  telefone: string;
  telefoneOriginal: string;
  status: "enviado" | "simulado" | "erro" | "ignorado";
  motivo?: string;
  messageId?: string;
}

export interface ProgressoDisparo {
  total: number;
  processados: number;
  enviados: number;
  erros: number;
  ignorados: number;
  interrompido?: string;
  arquivoResultado?: string;
}

const COLUNAS_TELEFONE = ["phone", "telefone", "whatsapp", "celular", "numero", "número", "fone"];

export function lerContatosCsv(caminho: string): Contato[] {
  const conteudo = readFileSync(caminho, "utf8").replace(/^﻿/, "");
  const primeiraLinha = conteudo.split(/\r?\n/, 1)[0] ?? "";
  const delimitador = (primeiraLinha.match(/;/g)?.length ?? 0) > (primeiraLinha.match(/,/g)?.length ?? 0) ? ";" : ",";
  return parse(conteudo, {
    columns: (cabecalho: string[]) => cabecalho.map((c) => c.trim()),
    delimiter: delimitador,
    skip_empty_lines: true,
    trim: true,
    relax_column_count: true,
  }) as Contato[];
}

export function detectarColunaTelefone(contatos: Contato[], preferida?: string): string {
  const colunas = Object.keys(contatos[0] ?? {});
  if (preferida) {
    if (!colunas.includes(preferida)) throw new Error(`Coluna "${preferida}" não encontrada. Colunas: ${colunas.join(", ")}`);
    return preferida;
  }
  const achada = colunas.find((c) => COLUNAS_TELEFONE.includes(c.toLowerCase()));
  if (!achada) throw new Error(`Nenhuma coluna de telefone encontrada. Colunas: ${colunas.join(", ")}`);
  return achada;
}

/** Substitui "{{coluna}}" / "{{coluna|padrão}}" pelos dados do contato. */
export function preencher(modelo: string, contato: Contato): string {
  return modelo.replace(/\{\{\s*([^}|]+?)\s*(?:\|([^}]*))?\}\}/g, (_, coluna: string, padrao?: string) => {
    const valor = contato[coluna]?.trim();
    return valor || padrao?.trim() || "";
  });
}

export function montarComponentes(modelo: ModeloDisparo, contato: Contato): ComponenteTemplate[] {
  const componentes: ComponenteTemplate[] = [];
  const texto = (bruto: string, campo: string) => {
    const valor = preencher(bruto, contato);
    if (!valor) throw new Error(`Variável "${campo}" ficou vazia para este contato (use {{coluna|padrão}})`);
    return valor;
  };

  if (modelo.headerImagemUrl) {
    componentes.push({ type: "header", parameters: [{ type: "image", image: { link: modelo.headerImagemUrl } }] });
  } else if (modelo.headerDocumentoUrl) {
    componentes.push({ type: "header", parameters: [{ type: "document", document: { link: modelo.headerDocumentoUrl } }] });
  } else if (modelo.headerVideoUrl) {
    componentes.push({ type: "header", parameters: [{ type: "video", video: { link: modelo.headerVideoUrl } }] });
  } else if (modelo.headerTexto) {
    componentes.push({ type: "header", parameters: [{ type: "text", text: texto(modelo.headerTexto, "header") }] });
  }

  if (modelo.variaveisNomeadas && Object.keys(modelo.variaveisNomeadas).length) {
    componentes.push({
      type: "body",
      parameters: Object.entries(modelo.variaveisNomeadas).map(([nome, valor]) => ({
        type: "text" as const,
        parameter_name: nome,
        text: texto(valor, nome),
      })),
    });
  } else if (modelo.variaveisCorpo?.length) {
    componentes.push({
      type: "body",
      parameters: modelo.variaveisCorpo.map((v, i) => ({ type: "text" as const, text: texto(v, `{{${i + 1}}}`) })),
    });
  }

  if (modelo.botaoUrlSufixo) {
    componentes.push({
      type: "button",
      sub_type: "url",
      index: "0",
      parameters: [{ type: "text", text: texto(modelo.botaoUrlSufixo, "botão URL") }],
    });
  }
  return componentes;
}

function lerOptOut(caminho: string | undefined, ddi: string): Set<string> {
  if (!caminho || !existsSync(caminho)) return new Set();
  return new Set(
    readFileSync(caminho, "utf8")
      .split(/\r?\n/)
      .map((l) => normalizarTelefone(l, ddi))
      .filter((t): t is string => !!t),
  );
}

function csvCelula(valor: unknown): string {
  const s = String(valor ?? "");
  return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

const espera = (ms: number) => new Promise((r) => setTimeout(r, ms));

export async function executarDisparo(
  client: WhatsAppClient,
  config: Config,
  opcoes: OpcoesDisparo,
): Promise<ProgressoDisparo> {
  const { modelo, contatos, dryRun = false } = opcoes;
  const idioma = modelo.idioma ?? "pt_BR";
  const colunaTelefone = detectarColunaTelefone(contatos, opcoes.colunaTelefone);
  const optOut = lerOptOut(opcoes.arquivoOptOut ?? resolve("data/optout.txt"), config.ddiPadrao);
  const intervaloMs = Math.ceil(1000 / config.msgPorSegundo);

  const arquivoResultado =
    opcoes.arquivoResultado ??
    resolve(`data/resultado-${new Date().toISOString().replace(/[:.]/g, "-")}${dryRun ? "-simulacao" : ""}.csv`);
  mkdirSync(dirname(arquivoResultado), { recursive: true });
  writeFileSync(arquivoResultado, "telefone,telefone_original,status,motivo,message_id\n");
  const registrar = (r: ResultadoContato) =>
    appendFileSync(
      arquivoResultado,
      [r.telefone, r.telefoneOriginal, r.status, r.motivo, r.messageId].map(csvCelula).join(",") + "\n",
    );

  const progresso: ProgressoDisparo = {
    total: contatos.length,
    processados: 0,
    enviados: 0,
    erros: 0,
    ignorados: 0,
    arquivoResultado,
  };
  const vistos = new Set<string>();

  for (const contato of contatos) {
    if (opcoes.sinalCancelamento?.aborted) {
      progresso.interrompido = "cancelado pelo usuário";
      break;
    }
    if (progresso.enviados >= config.limitePorExecucao) {
      progresso.interrompido = `limite de ${config.limitePorExecucao} envios por execução atingido`;
      break;
    }

    const original = contato[colunaTelefone] ?? "";
    const telefone = normalizarTelefone(original, config.ddiPadrao);
    progresso.processados++;

    let resultado: ResultadoContato;
    if (!telefone) {
      resultado = { telefone: "", telefoneOriginal: original, status: "ignorado", motivo: "telefone inválido" };
    } else if (vistos.has(telefone)) {
      resultado = { telefone, telefoneOriginal: original, status: "ignorado", motivo: "duplicado" };
    } else if (optOut.has(telefone)) {
      resultado = { telefone, telefoneOriginal: original, status: "ignorado", motivo: "opt-out" };
    } else {
      vistos.add(telefone);
      resultado = await enviarComRetentativa(client, telefone, original, modelo, idioma, contato, dryRun);
      if (resultado.status !== "simulado") await espera(intervaloMs);
    }

    registrar(resultado);
    if (resultado.status === "enviado" || resultado.status === "simulado") progresso.enviados++;
    else if (resultado.status === "erro") progresso.erros++;
    else progresso.ignorados++;
    opcoes.aoProgredir?.({ ...progresso });

    if (resultado.status === "erro" && resultado.motivo?.startsWith("FATAL")) {
      progresso.interrompido = resultado.motivo;
      break;
    }
  }

  return progresso;
}

async function enviarComRetentativa(
  client: WhatsAppClient,
  telefone: string,
  original: string,
  modelo: ModeloDisparo,
  idioma: string,
  contato: Contato,
  dryRun: boolean,
): Promise<ResultadoContato> {
  let componentes: ComponenteTemplate[];
  try {
    componentes = montarComponentes(modelo, contato);
  } catch (e) {
    return { telefone, telefoneOriginal: original, status: "ignorado", motivo: (e as Error).message };
  }
  if (dryRun) return { telefone, telefoneOriginal: original, status: "simulado" };

  for (let tentativa = 1; ; tentativa++) {
    try {
      const resposta = await client.enviarTemplate(telefone, modelo.template, idioma, componentes);
      return { telefone, telefoneOriginal: original, status: "enviado", messageId: resposta.messages?.[0]?.id };
    } catch (e) {
      if (e instanceof WhatsAppApiError) {
        if (e.fatal) return { telefone, telefoneOriginal: original, status: "erro", motivo: `FATAL: ${e.message}` };
        if (e.temporario && tentativa < 4) {
          await espera(2000 * 2 ** (tentativa - 1));
          continue;
        }
      }
      return { telefone, telefoneOriginal: original, status: "erro", motivo: (e as Error).message };
    }
  }
}
