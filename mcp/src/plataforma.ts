/**
 * Cliente da API da plataforma de disparos (raiz do repositório, `/api/v1`, header X-API-Key).
 *
 * A plataforma é quem aplica supressões, teto, janela, aprovação e trilha; o MCP só conversa com ela.
 * Aprovar, iniciar, pausar e cancelar campanha são ações do painel (quatro olhos) e não existem aqui.
 */
export interface ConfigPlataforma {
  url: string;
  chave: string;
}

export function carregarConfigPlataforma(): ConfigPlataforma {
  return {
    url: (process.env.DISPARO_API_URL ?? "").trim().replace(/\/+$/, ""),
    chave: (process.env.DISPARO_API_KEY ?? "").trim(),
  };
}

export class PlataformaError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message);
  }
}

export class PlataformaClient {
  constructor(private readonly config: ConfigPlataforma) {}

  configurada(): boolean {
    return this.config.url !== "" && this.config.chave !== "";
  }

  private async request<T>(caminho: string, init: { method?: string; body?: unknown; query?: Record<string, string | undefined> } = {}): Promise<T> {
    if (!this.configurada()) {
      throw new PlataformaError(0, "Plataforma não configurada: defina DISPARO_API_URL (ex.: https://disparo.unicopag.com.br) e DISPARO_API_KEY no ambiente do MCP.");
    }

    const url = new URL(`${this.config.url}/api/v1/${caminho.replace(/^\/+/, "")}`);
    for (const [k, v] of Object.entries(init.query ?? {})) {
      if (v !== undefined && v !== "") url.searchParams.set(k, v);
    }

    const resposta = await fetch(url, {
      method: init.method ?? "GET",
      headers: {
        "X-API-Key": this.config.chave,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: init.body === undefined ? undefined : JSON.stringify(init.body),
    });

    const texto = await resposta.text();
    let json: unknown = {};
    try {
      json = texto ? JSON.parse(texto) : {};
    } catch {
      json = { bruto: texto.slice(0, 500) };
    }

    if (!resposta.ok) {
      const mensagem = (json as { message?: string; error?: string }).message ?? (json as { error?: string }).error ?? "a plataforma recusou";
      throw new PlataformaError(resposta.status, `Plataforma ${resposta.status}: ${mensagem}`);
    }

    return json as T;
  }

  saude() {
    return this.request<Record<string, unknown>>("health");
  }

  /** Bloqueios (supressões) desde uma data: o que o KYC também lê. */
  listarBloqueios(desde?: string) {
    return this.request<unknown>("suppressions", { query: { since: desde } });
  }

  /** Bloqueia um telefone para sempre (escopo tudo) ou só para marketing. */
  bloquear(telefone: string, escopo: "tudo" | "marketing", motivo: string, detalhes?: string) {
    return this.request<unknown>("suppressions", {
      method: "POST",
      body: { telefone, escopo, motivo, origem: "mcp", detalhes },
    });
  }

  consultarContato(telefone: string) {
    return this.request<unknown>(`contacts/${encodeURIComponent(telefone)}`);
  }

  listarTemplates(remetenteId: number) {
    return this.request<unknown>(`senders/${remetenteId}/templates`);
  }

  /** Cria a campanha em RASCUNHO: prévia, aprovação e início acontecem no painel. */
  criarCampanhaRascunho(campanha: Record<string, unknown>) {
    return this.request<unknown>("campaigns", { method: "POST", body: campanha });
  }

  statusCampanha(id: number) {
    return this.request<unknown>(`campaigns/${id}`);
  }
}
