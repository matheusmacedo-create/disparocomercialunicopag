#!/usr/bin/env node
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { parseArgs } from "node:util";
import { carregarConfig } from "./config.js";
import { WhatsAppClient } from "./whatsapp.js";
import { executarDisparo, lerContatosCsv, type ModeloDisparo } from "./dispatch.js";

const AJUDA = `
Uso: npm run disparo -- --csv data/contatos.csv --template NOME [opções]
     npm run disparo -- --config campanha.json --csv data/contatos.csv

  --csv <arquivo>        CSV de contatos (colunas: phone, first_name, ...)
  --template <nome>      Template aprovado na Meta
  --idioma <código>      Idioma do template (padrão: pt_BR)
  --var <valor>          Variável do corpo, repetível: --var "{{first_name|tudo bem}}"
  --imagem <url>         Imagem do cabeçalho
  --coluna <nome>        Coluna de telefone (detecta automaticamente)
  --config <json>        Arquivo JSON com a campanha (campos de ModeloDisparo)
  --enviar               Envia de verdade (sem isso é SIMULAÇÃO)
`;

const { values } = parseArgs({
  options: {
    csv: { type: "string" },
    template: { type: "string" },
    idioma: { type: "string" },
    var: { type: "string", multiple: true },
    imagem: { type: "string" },
    coluna: { type: "string" },
    config: { type: "string" },
    enviar: { type: "boolean", default: false },
    help: { type: "boolean", short: "h" },
  },
});

if (values.help || !values.csv || (!values.template && !values.config)) {
  console.log(AJUDA);
  process.exit(values.help ? 0 : 1);
}

const base: Partial<ModeloDisparo> = values.config ? JSON.parse(readFileSync(values.config, "utf8")) : {};
const modelo: ModeloDisparo = {
  ...base,
  template: values.template ?? base.template!,
  idioma: values.idioma ?? base.idioma ?? "pt_BR",
  variaveisCorpo: values.var ?? base.variaveisCorpo,
  headerImagemUrl: values.imagem ?? base.headerImagemUrl,
};

const config = carregarConfig();
const client = new WhatsAppClient(config);
const contatos = lerContatosCsv(resolve(values.csv));
const dryRun = !values.enviar;

if (!dryRun) {
  const info = await client.infoNumero();
  console.log(`Número: ${info.display_phone_number} (${info.verified_name}) — qualidade ${info.quality_rating}, limite ${info.messaging_limit_tier ?? "?"}`);
  if (info.quality_rating === "RED") {
    console.error("Qualidade do número está RED. Disparo abortado para não bloquear o número.");
    process.exit(1);
  }
}

console.log(`${dryRun ? "SIMULAÇÃO" : "ENVIO REAL"}: template "${modelo.template}" para ${contatos.length} contatos`);
const controle = new AbortController();
process.on("SIGINT", () => {
  console.log("\nCancelando após a mensagem atual...");
  controle.abort();
});

const resultado = await executarDisparo(client, config, {
  modelo,
  contatos,
  colunaTelefone: values.coluna,
  dryRun,
  sinalCancelamento: controle.signal,
  aoProgredir: (p) =>
    process.stdout.write(`\r${p.processados}/${p.total} | ok ${p.enviados} | erro ${p.erros} | ignorados ${p.ignorados}   `),
});

console.log(`\n${resultado.interrompido ? `Interrompido: ${resultado.interrompido}\n` : ""}Relatório: ${resultado.arquivoResultado}`);
if (dryRun) console.log("Nada foi enviado. Revise o relatório e rode novamente com --enviar.");
