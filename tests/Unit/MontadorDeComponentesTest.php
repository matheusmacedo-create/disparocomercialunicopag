<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Envio\MontadorDeComponentes;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Variaveis;
use PHPUnit\Framework\TestCase;

class MontadorDeComponentesTest extends TestCase
{
    /** @var array<string, string> */
    private array $dados = [
        'primeiro_nome' => 'Ana',
        'nome' => 'Ana Souza',
        'email' => '',
        'empresa' => 'Loja da Ana',
        'atributos.oferta' => 'taxa zero',
    ];

    /** @return array<string, mixed> */
    private function template(array $components, string $nome = 'oferta_v1'): array
    {
        return ['name' => $nome, 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => $components];
    }

    public function test_corpo_posicional_com_padrao(): void
    {
        $definicao = $this->template([
            ['type' => 'BODY', 'text' => 'Oi {{1}}, temos {{2}} para a {{3}}.'],
        ]);

        $componentes = MontadorDeComponentes::montar($definicao, [
            'corpo' => ['{{primeiro_nome|tudo bem}}', '{{atributos.oferta}}', '{{empresa}}'],
        ], $this->dados);

        $this->assertSame([[
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'Ana'],
                ['type' => 'text', 'text' => 'taxa zero'],
                ['type' => 'text', 'text' => 'Loja da Ana'],
            ],
        ]], $componentes);
    }

    public function test_so_1_sem_variaveis_na_campanha_usa_o_primeiro_nome(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Oi {{1}}!']]);

        $componentes = MontadorDeComponentes::montar($definicao, [], $this->dados);

        $this->assertSame('Ana', $componentes[0]['parameters'][0]['text']);

        $semNome = MontadorDeComponentes::montar($definicao, [], ['primeiro_nome' => '']);
        $this->assertSame('tudo bem', $semNome[0]['parameters'][0]['text']);
    }

    public function test_variavel_vazia_sem_padrao_e_recusada_antes_do_envio(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Oi {{1}}, seu e-mail {{2}}']]);

        try {
            MontadorDeComponentes::montar($definicao, ['corpo' => ['{{primeiro_nome}}', '{{email}}']], $this->dados);
            $this->fail('deveria recusar');
        } catch (WhatsAppException $e) {
            $this->assertSame('variavel_vazia', $e->codigo);
            $this->assertStringContainsString('{{2}}', $e->getMessage());
        }
    }

    public function test_corpo_pede_variavel_que_a_campanha_nao_diz(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Oi {{1}} e {{2}}']]);

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('{{2}}');
        MontadorDeComponentes::montar($definicao, ['corpo' => ['{{primeiro_nome}}']], $this->dados);
    }

    public function test_template_nomeado(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Oi {{nome}}, {{oferta}}']]) + ['parameter_format' => 'NAMED'];

        $componentes = MontadorDeComponentes::montar($definicao, [
            'corpo' => ['nome' => '{{primeiro_nome}}', 'oferta' => '{{atributos.oferta}}'],
        ], $this->dados);

        $this->assertSame([
            ['type' => 'text', 'text' => 'Ana', 'parameter_name' => 'nome'],
            ['type' => 'text', 'text' => 'taxa zero', 'parameter_name' => 'oferta'],
        ], $componentes[0]['parameters']);
    }

    public function test_cabecalho_de_midia_e_botao_de_url(): void
    {
        $definicao = $this->template([
            ['type' => 'HEADER', 'format' => 'IMAGE'],
            ['type' => 'BODY', 'text' => 'Oi {{1}}'],
            ['type' => 'BUTTONS', 'buttons' => [
                ['type' => 'QUICK_REPLY', 'text' => 'Quero'],
                ['type' => 'URL', 'text' => 'Abrir', 'url' => 'https://unicopag.com.br/oferta/{{1}}'],
            ]],
        ]);

        $componentes = MontadorDeComponentes::montar($definicao, [
            'cabecalho_midia_url' => 'https://cdn.exemplo.com/banner.jpg',
            'botao_url_sufixo' => 'ana-{{atributos.oferta|geral}}',
        ], $this->dados);

        $this->assertSame(['type' => 'header', 'parameters' => [['type' => 'image', 'image' => ['link' => 'https://cdn.exemplo.com/banner.jpg']]]], $componentes[0]);
        $this->assertSame('body', $componentes[1]['type']);
        $this->assertSame([
            'type' => 'button', 'sub_type' => 'url', 'index' => '1',
            'parameters' => [['type' => 'text', 'text' => 'ana-taxa zero']],
        ], $componentes[2]);
    }

    public function test_cabecalho_de_midia_sem_url_e_recusado(): void
    {
        $definicao = $this->template([['type' => 'HEADER', 'format' => 'VIDEO'], ['type' => 'BODY', 'text' => 'x']]);

        $this->expectException(WhatsAppException::class);
        MontadorDeComponentes::montar($definicao, [], $this->dados);
    }

    public function test_link_em_variavel_e_recusado_salvo_permissao_explicita(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Veja {{1}}']]);
        $dados = ['atributos.site' => 'https://golpe.example.com/x'];

        try {
            MontadorDeComponentes::montar($definicao, ['corpo' => ['{{atributos.site}}']], $dados);
            $this->fail('deveria recusar link');
        } catch (WhatsAppException $e) {
            $this->assertSame('variavel_com_link', $e->codigo);
        }

        $componentes = MontadorDeComponentes::montar($definicao, ['corpo' => ['{{atributos.site}}'], 'permitir_links' => true], $dados);
        $this->assertSame('https://golpe.example.com/x', $componentes[0]['parameters'][0]['text']);
    }

    public function test_variavel_longa_demais_e_recusada(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Oi {{1}}']]);

        $this->expectException(WhatsAppException::class);
        $this->expectExceptionMessage('caracteres');
        MontadorDeComponentes::montar($definicao, ['corpo' => ['{{atributos.x}}']], ['atributos.x' => str_repeat('a', 200)]);
    }

    public function test_template_sem_variaveis_nao_manda_componentes(): void
    {
        $definicao = $this->template([['type' => 'BODY', 'text' => 'Aviso fixo.'], ['type' => 'FOOTER', 'text' => 'ÚnicoPag']]);

        $this->assertSame([], MontadorDeComponentes::montar($definicao, [], $this->dados));
    }

    public function test_resumo_para_a_trilha(): void
    {
        $resumo = MontadorDeComponentes::resumo('oferta_v1', [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => 'Ana']]]]);

        $this->assertSame('oferta_v1 | body {{1}}=Ana', $resumo);
    }

    public function test_render_e_desconhecidas(): void
    {
        $this->assertSame('Oi Ana, tudo bem', Variaveis::render('Oi {{primeiro_nome}}, {{email|tudo bem}}', $this->dados));
        $this->assertSame('', Variaveis::render('{{nada}}', $this->dados));
        $this->assertSame(['nada'], Variaveis::desconhecidas('{{primeiro_nome}} {{nada}} {{atributos.x}}', $this->dados));
    }
}
