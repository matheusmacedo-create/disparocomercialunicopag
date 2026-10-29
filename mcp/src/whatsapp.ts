import type { Config } from "./config.js";

export interface ErroMeta {
  message: string;
  type?: string;
  code?: number;
  error_subcode?: number;
  error_data?: { details?: string };
  fbtrace_id?: string;
}

export class WhatsAppApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly meta: ErroMeta | undefined,
  ) {
    const detalhe = meta?.error_data?.details ? ` — ${meta.error_data.details}` : "";
    super(`Meta API ${status}${meta?.code ? ` (código ${meta.code})` : ""}: ${meta?.message ?? "erro desconhecido"}${detalhe}`);
  }

  /** Erros transitórios que valem nova tentativa (limites de taxa / indisponibilidade). */
  get temporario(): boolean {
    const codigo = this.meta?.code;
    return this.status === 429 || this.status >= 500 || codigo === 130429 || codigo === 131056 || codigo === 2;
  }

  /** Erros que indicam problema na conta/token: o disparo inteiro deve parar. */
  get fatal(): boolean {
    const codigo = this.meta?.code;
    return (
      this.status === 401 ||
      codigo === 190 || // token inválido/expirado
      codigo === 10 || codigo === 200 || // permissão
      codigo === 131031 || // conta bloqueada
      codigo === 131048 || // limite por spam: parar para proteger o número
      codigo === 368 // bloqueio temporário por violação de política
    );
  }
}

export type ParametroTexto = { type: "text"; text: string; parameter_name?: string };

export interface ComponenteTemplate {
  type: "header" | "body" | "button";
  sub_type?: "url" | "quick_reply";
  index?: string;
  parameters: Array<
    | ParametroTexto
    | { type: "image"; image: { link: string } }
    | { type: "document"; document: { link: string; filename?: string } }
    | { type: "video"; video: { link: string } }
    | { type: "payload"; payload: string }
  >;
}

export interface RespostaEnvio {
  messaging_product: "whatsapp";
  contacts: Array<{ input: string; wa_id: string }>;
  messages: Array<{ id: string; message_status?: string }>;
}

export class WhatsAppClient {
  private readonly base: string;

  constructor(private readonly config: Config) {
    this.base = `https://graph.facebook.com/${config.graphVersion}`;
  }

  private async request<T>(caminho: string, init: { method?: string; body?: unknown } = {}): Promise<T> {
    if (!this.config.token) {
      throw new Error("WHATSAPP_TOKEN não configurado: gere o token permanente do usuário do sistema e coloque no .env");
    }
    const resposta = await fetch(`${this.base}/${caminho}`, {
      method: init.method ?? "GET",
      headers: {
        Authorization: `Bearer ${this.config.token}`,
        "Content-Type": "application/json",
      },
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });
    const texto = await resposta.text();
    const json = texto ? JSON.parse(texto) : {};
    if (!resposta.ok) {
      throw new WhatsAppApiError(resposta.status, json?.error);
    }
    return json as T;
  }

  /** Dados do número da Unicopag: nome verificado, qualidade e limite diário. */
  infoNumero() {
    return this.request<{
      id: string;
      display_phone_number: string;
      verified_name: string;
      quality_rating: string;
      messaging_limit_tier?: string;
      code_verification_status?: string;
      status?: string;
      name_status?: string;
    }>(
      `${this.config.phoneNumberId}?fields=display_phone_number,verified_name,quality_rating,messaging_limit_tier,code_verification_status,status,name_status`,
    );
  }

  async listarTemplates(filtroStatus?: string) {
    if (!this.config.wabaId) {
      throw new Error("Configure WHATSAPP_WABA_ID para listar templates");
    }
    const templates: Array<{
      id: string;
      name: string;
      status: string;
      language: string;
      category: string;
      parameter_format?: string;
      components: unknown[];
    }> = [];
    let caminho: string | undefined =
      `${this.config.wabaId}/message_templates?fields=id,name,status,language,category,parameter_format,components&limit=100`;
    while (caminho) {
      const pagina: { data: typeof templates; paging?: { next?: string } } = await this.request(caminho);
      templates.push(...pagina.data);
      caminho = pagina.paging?.next?.replace(`${this.base}/`, "");
    }
    return filtroStatus ? templates.filter((t) => t.status === filtroStatus.toUpperCase()) : templates;
  }

  enviarTemplate(para: string, nome: string, idioma: string, componentes: ComponenteTemplate[] = []) {
    return this.request<RespostaEnvio>(`${this.config.phoneNumberId}/messages`, {
      method: "POST",
      body: {
        messaging_product: "whatsapp",
        recipient_type: "individual",
        to: para,
        type: "template",
        template: {
          name: nome,
          language: { code: idioma },
          ...(componentes.length ? { components: componentes } : {}),
        },
      },
    });
  }

  /** Texto livre: só é entregue se o contato falou com a Unicopag nas últimas 24h. */
  enviarTexto(para: string, texto: string, previewUrl = false) {
    return this.request<RespostaEnvio>(`${this.config.phoneNumberId}/messages`, {
      method: "POST",
      body: {
        messaging_product: "whatsapp",
        recipient_type: "individual",
        to: para,
        type: "text",
        text: { body: texto, preview_url: previewUrl },
      },
    });
  }

  /** Passo 1 do registro: a Meta envia um código de 6 dígitos por SMS ou ligação para o número. */
  solicitarCodigo(metodo: "SMS" | "VOICE" = "SMS", idioma = "pt_BR") {
    return this.request<{ success: boolean }>(
      `${this.config.phoneNumberId}/request_code?code_method=${metodo}&language=${idioma}`,
      { method: "POST" },
    );
  }

  /** Passo 2 do registro: confirma o código recebido. */
  verificarCodigo(codigo: string) {
    return this.request<{ success: boolean }>(
      `${this.config.phoneNumberId}/verify_code?code=${encodeURIComponent(codigo)}`,
      { method: "POST" },
    );
  }

  /** Passo 3: registra o número na Cloud API. O PIN vira a verificação em duas etapas do número. */
  registrarNumero(pin: string) {
    return this.request<{ success: boolean }>(`${this.config.phoneNumberId}/register`, {
      method: "POST",
      body: { messaging_product: "whatsapp", pin },
    });
  }
}
