import "dotenv/config";

function numero(nome: string, padrao: number): number {
  const bruto = process.env[nome];
  if (!bruto) return padrao;
  const valor = Number(bruto);
  if (!Number.isFinite(valor) || valor <= 0) {
    throw new Error(`Variável ${nome} deve ser um número positivo (recebido: ${bruto})`);
  }
  return valor;
}

// Ativos da Unicopag no Business Manager (IDs públicos, não são segredo)
// Número: +55 21 92367-4860 — "Matheus Macedo -ÚnicoPag Checkout|Gateway"
export const UNICOPAG_PHONE_NUMBER_ID = "1161905150334981";
export const UNICOPAG_WABA_ID = "1278445183736183";
export const UNICOPAG_BUSINESS_ID = "1127366268551701";

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
    // O token só é exigido na primeira chamada à API, para o MCP subir mesmo sem ele
    token: process.env.WHATSAPP_TOKEN?.trim() ?? "",
    phoneNumberId: process.env.WHATSAPP_PHONE_NUMBER_ID?.trim() || UNICOPAG_PHONE_NUMBER_ID,
    wabaId: process.env.WHATSAPP_WABA_ID?.trim() || UNICOPAG_WABA_ID,
    graphVersion: process.env.GRAPH_API_VERSION?.trim() || "v23.0",
    msgPorSegundo: numero("DISPARO_MSG_POR_SEGUNDO", 5),
    limitePorExecucao: numero("DISPARO_LIMITE_POR_EXECUCAO", 1000),
    ddiPadrao: process.env.DISPARO_DDI_PADRAO?.trim() || "55",
  };
}
