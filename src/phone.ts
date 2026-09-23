/**
 * Normaliza um telefone para o formato E.164 sem "+" exigido pela Cloud API
 * (ex.: "(22) 99876-5432" -> "5522998765432"). Retorna null se inválido.
 */
export function normalizarTelefone(bruto: string, ddiPadrao = "55"): string | null {
  let digitos = String(bruto ?? "").replace(/\D/g, "");
  if (!digitos) return null;

  // Remove prefixo internacional "00" e zeros de operadora/DDD ("0 22 ...")
  if (digitos.startsWith("00")) digitos = digitos.slice(2);
  else digitos = digitos.replace(/^0+/, "");

  // Número nacional brasileiro (DDD + 8/9 dígitos) sem DDI
  if (ddiPadrao === "55" && (digitos.length === 10 || digitos.length === 11)) {
    digitos = ddiPadrao + digitos;
  }

  if (digitos.startsWith("55")) {
    // 55 + DDD (2) + 8 ou 9 dígitos
    if (digitos.length !== 12 && digitos.length !== 13) return null;
    const ddd = Number(digitos.slice(2, 4));
    if (ddd < 11 || ddd > 99) return null;
    return digitos;
  }

  return digitos.length >= 10 && digitos.length <= 15 ? digitos : null;
}
