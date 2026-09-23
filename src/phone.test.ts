import { test } from "node:test";
import assert from "node:assert/strict";
import { normalizarTelefone } from "./phone.js";

test("normaliza formatos brasileiros comuns", () => {
  assert.equal(normalizarTelefone("(22) 99876-5432"), "5522998765432");
  assert.equal(normalizarTelefone("+55 22 99876-5432"), "5522998765432");
  assert.equal(normalizarTelefone("022 99876 5432"), "5522998765432");
  assert.equal(normalizarTelefone("22 2525-1234"), "552225251234");
  assert.equal(normalizarTelefone("0055 22 998765432"), "5522998765432");
});

test("rejeita números inválidos", () => {
  assert.equal(normalizarTelefone(""), null);
  assert.equal(normalizarTelefone("12345"), null);
  assert.equal(normalizarTelefone("55 05 99876-5432"), null);
});
