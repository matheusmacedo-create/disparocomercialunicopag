import "dotenv/config";

function obrigatoria(nome: string): string {
  const valor = process.env[nome]?.trim();
  if (!valor) {
    throw new Error(`Variável de ambiente ${nome} não configurada (veja .env.example)`);
  }
  return valor;
}

function numero(nome: string, padrao: number): number {
  const bruto = process.env[nome];
  if (!bruto) return padrao;
  const valor = Number(bruto);
  if (!Number.isFinite(valor) || valor <= 0) {
    throw new Error(`Variável ${nome} deve ser um número positivo (recebido: ${bruto})`);
  }
  return valor;
}

export interface Config {
  token: string;
  phoneNumberId: string;
  wabaId?: string;
  graphVersion: string;
  msgPorSegundo: number;
  limitePorExecucao: number;
  ddiPadrao: string;
}

export function carregarConfig(): Config {
  return {
    token: obrigatoria("WHATSAPP_TOKEN"),
    phoneNumberId: obrigatoria("WHATSAPP_PHONE_NUMBER_ID"),
    wabaId: process.env.WHATSAPP_WABA_ID?.trim() || undefined,
    graphVersion: process.env.GRAPH_API_VERSION?.trim() || "v23.0",
    msgPorSegundo: numero("DISPARO_MSG_POR_SEGUNDO", 5),
    limitePorExecucao: numero("DISPARO_LIMITE_POR_EXECUCAO", 1000),
    ddiPadrao: process.env.DISPARO_DDI_PADRAO?.trim() || "55",
  };
}
