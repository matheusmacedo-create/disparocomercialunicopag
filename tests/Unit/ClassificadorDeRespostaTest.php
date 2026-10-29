<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Webhooks\ClassificadorDeResposta;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * As listas do KYC (KapsoWebhookTest), que o verificador de produção conferiu: quem pede para parar
 * bloqueia; quem diz "agora não" fica; o atendimento no mesmo número não bloqueia.
 */
class ClassificadorDeRespostaTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function pedidosParaPararEmResposta(): array
    {
        return [
            'botão denunciar' => ['Denunciar'],
            'botão sair da lista' => ['Sair da lista'],
            'botão me exclui da lista' => ['Me exclui da lista'],
            'botão da Meta' => ['Parar promoções'],
            'palavra solta com pontuação' => ['PARE!'],
            'stop' => ['Stop'],
            'não quero mais, com acento' => ['NÃO QUERO mais isso'],
            'descadastrar' => ['me descadastra por favor'],
            'pare de me mandar' => ['Pare de me mandar mensagem, por favor'],
            'não quero receber' => ['nao quero receber essas mensagens'],
            'cancelar' => ['Cancelar'],
            'não tenho interesse' => ['Não tenho interesse'],
            'não quero ofertas' => ['não quero ofertas'],
        ];
    }

    #[DataProvider('pedidosParaPararEmResposta')]
    public function test_em_resposta_a_template_e_pedido_para_parar(string $texto): void
    {
        $this->assertSame(ClassificadorDeResposta::PARAR, ClassificadorDeResposta::classificar([$texto], true));
    }

    /** @return array<string, array{string}> */
    public static function pedidosColoquiais(): array
    {
        return [
            'para de mandar mensagem' => ['Para de mandar mensagem'],
            'não manda mais' => ['Não manda mais'],
            'não me manda mais nada' => ['Não me manda mais nada'],
            'para de me enviar mensagem' => ['Para de me enviar mensagem'],
            'pode me excluir da lista' => ['Pode me excluir da lista'],
            'me exclua da lista' => ['Me exclua da lista'],
            'me remova da lista' => ['Me remova da lista'],
            'pare sozinho' => ['pare'],
            'parar promoções' => ['Parar promoções'],
            'não quero mais receber promoções' => ['não quero mais receber promoções'],
            'para de mandar oferta' => ['para de me mandar oferta'],
        ];
    }

    #[DataProvider('pedidosColoquiais')]
    public function test_pedido_coloquial_vale_em_qualquer_conversa(string $texto): void
    {
        $this->assertSame(ClassificadorDeResposta::PARAR, ClassificadorDeResposta::classificar([$texto], false));
        $this->assertTrue(ClassificadorDeResposta::pedidoParaPararNoHistorico([$texto], false));
    }

    /** @return array<string, array{string}> */
    public static function naoAgora(): array
    {
        return [
            'não sozinho' => ['Não.'],
            'não, obrigado' => ['Não, obrigado'],
            'botão prefiro não' => ['Prefiro não'],
            'botão agora não' => ['Agora não'],
            'botão não preciso mais' => ['Não preciso mais'],
            'depois' => ['Depois'],
        ];
    }

    #[DataProvider('naoAgora')]
    public function test_nao_agora_em_resposta_nao_bloqueia(string $texto): void
    {
        $this->assertSame(ClassificadorDeResposta::NAO_AGORA, ClassificadorDeResposta::classificar([$texto], true));
        $this->assertNull(ClassificadorDeResposta::classificar([$texto], false));
        $this->assertFalse(ClassificadorDeResposta::pedidoParaPararNoHistorico([$texto], false));
    }

    /** @return array<string, array{string}> */
    public static function atendimento(): array
    {
        return [
            'conta vai parar' => ['Pode sim, minha conta vai parar?'],
            'parar de enrolar' => ['pode parar de enrolar e mandar o link?'],
            'parar de vender' => ['Sim, não posso parar de vender'],
            'não quero mais esperar' => ['Não quero mais esperar, me manda o link'],
            'não pare' => ['Não pare, pode mandar'],
            'não quero receber por e-mail' => ['Pode sim, só não quero receber por e-mail'],
            'descadastro chave pix' => ['Como descadastro minha chave pix antiga?'],
            'descadastrar cartão' => ['Quero descadastrar o cartão antigo'],
            'não quero receber pelo pix' => ['Não quero receber pelo Pix, só no cartão'],
            'não envie mais o boleto' => ['Não envie mais o boleto, já paguei'],
            'conta descadastrada' => ['Por que minha conta foi descadastrada?'],
            'quer a maquininha' => ['Quero a maquininha, como faço?'],
        ];
    }

    #[DataProvider('atendimento')]
    public function test_atendimento_nao_bloqueia(string $texto): void
    {
        $this->assertNull(ClassificadorDeResposta::classificar([$texto], true));
        $this->assertNull(ClassificadorDeResposta::classificar([$texto], false));
        $this->assertFalse(ClassificadorDeResposta::pedidoParaPararNoHistorico([$texto], false));
    }

    public function test_fora_de_resposta_a_template_cancelar_e_atendimento(): void
    {
        $this->assertNull(ClassificadorDeResposta::classificar(['Cancelar'], false));
        $this->assertNull(ClassificadorDeResposta::classificar(['Denunciar'], false));
        // ...mas o botão explícito num template nosso conta no histórico.
        $this->assertTrue(ClassificadorDeResposta::pedidoParaPararNoHistorico(['Denunciar'], true));
        $this->assertFalse(ClassificadorDeResposta::pedidoParaPararNoHistorico(['Denunciar'], false));
    }

    /** @return array<string, array{string}> */
    public static function pedidosLgpd(): array
    {
        return [
            'apague meus dados' => ['Apague meus dados, por favor'],
            'exclua meu cadastro' => ['Quero que excluam meu cadastro'],
            'remova todos os meus dados' => ['Removam todos os meus dados'],
            'acesso aos dados' => ['Quero acesso aos meus dados'],
            'lgpd' => ['Pela LGPD, quero saber o que vocês têm sobre mim'],
        ];
    }

    #[DataProvider('pedidosLgpd')]
    public function test_pedido_do_titular_e_classe_propria(string $texto): void
    {
        $this->assertSame(ClassificadorDeResposta::PEDIDO_LGPD, ClassificadorDeResposta::classificar([$texto], false));
    }

    public function test_motivo_e_texto_normalizado_curto(): void
    {
        $this->assertSame('parar promocoes', ClassificadorDeResposta::motivo(['Parar promoções']));
        $this->assertSame('pediu_para_parar', ClassificadorDeResposta::motivo(['', null]));
    }
}
