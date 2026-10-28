<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Telefone;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Estende o TestCase da aplicação: o hash lê o DISPARO_HASH_PEPPER da config. */
class TelefoneTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function formatos(): array
    {
        return [
            'parênteses e hífen' => ['(22) 99876-5432', '+5522998765432'],
            'com +55' => ['+55 22 99876-5432', '+5522998765432'],
            'zero de operadora' => ['022 99876 5432', '+5522998765432'],
            'fixo' => ['22 2525-1234', '+552225251234'],
            '00 internacional' => ['0055 22 998765432', '+5522998765432'],
            'só dígitos com 55' => ['5521998765432', '+5521998765432'],
            'vazio' => ['', null],
            'curto' => ['12345', null],
            'DDD inválido' => ['55 05 99876-5432', null],
            'celular sem o 9' => ['21 8765-4321', null],
            'americano com +' => ['+1 203-660-2780', null],
            'americano sem + parece BR mas não casa a anatomia' => ['12036602780', null],
        ];
    }

    #[DataProvider('formatos')]
    public function test_normaliza_para_e164(string $bruto, ?string $esperado): void
    {
        $this->assertSame($esperado, Telefone::normalizar($bruto));
    }

    public function test_para_envio_tira_o_mais(): void
    {
        $this->assertSame('5522998765432', Telefone::paraEnvio('(22) 99876-5432'));
        $this->assertNull(Telefone::paraEnvio('abc'));
    }

    public function test_mascara_mostra_ddd_e_final(): void
    {
        $this->assertSame('+55 22 9****-5432', Telefone::mascarar('+5522998765432'));
        $this->assertSame('+55 22 2***-1234', Telefone::mascarar('+552225251234'));
        $this->assertSame('*******2780', Telefone::mascarar('+12036602780'));
        $this->assertSame('(sem telefone)', Telefone::mascarar(null));
    }

    public function test_formata_para_o_painel(): void
    {
        $this->assertSame('+55 (22) 99876-5432', Telefone::formatar('+5522998765432'));
        $this->assertSame('+55 (22) 2525-1234', Telefone::formatar('+552225251234'));
    }

    public function test_formas_inclui_o_nono_digito_quando_a_meta_o_omite(): void
    {
        $this->assertSame(['+552198765432', '+5521998765432'], Telefone::formas('552198765432'));
        // Fixo não ganha 9: casaria o celular de outra pessoa.
        $this->assertSame(['+552125251234'], Telefone::formas('552125251234'));
        $this->assertSame(['+5521998765432'], Telefone::formas('5521998765432'));
    }

    public function test_hash_e_estavel_e_ignora_o_mais(): void
    {
        $this->assertSame(Telefone::hash('+5521998765432'), Telefone::hash('5521998765432'));
        $this->assertSame(64, strlen(Telefone::hash('+5521998765432')));
        // HMAC com segredo: não é o sha256 puro (enumerável).
        $this->assertNotSame(hash('sha256', '5521998765432'), Telefone::hash('+5521998765432'));
        $this->assertStringStartsWith('anon:', Telefone::anonimo('+5521998765432'));
    }

    public function test_sem_pepper_nenhum_hash_sai(): void
    {
        config(['disparo.hash_pepper' => '']);

        $this->expectException(\RuntimeException::class);
        Telefone::hash('+5521998765432');
    }

    public function test_canonico_poe_o_nono_digito_no_celular_antigo(): void
    {
        $this->assertSame('+5521998765432', Telefone::canonico('552198765432'));
        $this->assertSame('+5521998765432', Telefone::canonico('+55 21 99876-5432'));
        $this->assertSame('+552125251234', Telefone::canonico('552125251234'));
        $this->assertNull(Telefone::canonico('123'));
    }

    public function test_fuso_pelo_ddd(): void
    {
        $this->assertSame('America/Sao_Paulo', Telefone::fusoDoDdd('+5521998765432'));
        $this->assertSame('America/Manaus', Telefone::fusoDoDdd('+5592998765432'));
        $this->assertSame('America/Rio_Branco', Telefone::fusoDoDdd('+5568998765432'));
        $this->assertSame('America/Cuiaba', Telefone::fusoDoDdd('+5565998765432'));
        $this->assertNull(Telefone::fusoDoDdd('+12036602780'));
    }
}
