#!/usr/bin/env node
import { carregarConfig } from "./config.js";
import { WhatsAppClient } from "./whatsapp.js";

const AJUDA = `
Registro do número na Cloud API (resolve status DISCONNECTED):

  npm run registrar -- status                 # mostra status do número
  npm run registrar -- solicitar [SMS|VOICE]  # Meta envia código de 6 dígitos
  npm run registrar -- verificar 123456       # confirma o código recebido
  npm run registrar -- registrar 654321       # ativa o número com um PIN de 6 dígitos
`;

const [etapa, valor] = process.argv.slice(2);
const client = new WhatsAppClient(carregarConfig());

try {
  switch (etapa) {
    case "status":
      console.log(await client.infoNumero());
      break;
    case "solicitar":
      console.log(await client.solicitarCodigo(valor === "VOICE" ? "VOICE" : "SMS"));
      break;
    case "verificar":
      if (!/^\d{6}$/.test(valor ?? "")) throw new Error("Informe o código de 6 dígitos");
      console.log(await client.verificarCodigo(valor));
      break;
    case "registrar":
      if (!/^\d{6}$/.test(valor ?? "")) throw new Error("Informe um PIN de 6 dígitos (guarde-o: é a verificação em duas etapas)");
      console.log(await client.registrarNumero(valor));
      break;
    default:
      console.log(AJUDA);
      process.exit(etapa ? 1 : 0);
  }
} catch (e) {
  console.error((e as Error).message);
  process.exit(1);
}
