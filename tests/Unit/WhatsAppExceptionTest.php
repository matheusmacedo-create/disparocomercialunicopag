<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WhatsApp\WhatsAppException;
use PHPUnit\Framework\TestCase;

class WhatsAppExceptionTest extends TestCase
{
    public function test_recusa_da_conta_afeta_todos_e_nao_e_transitoria(): void
    {
        foreach (['141006', '131042', '131031', '368', '131048', '190', 'insufficient_credits', 'sandbox'] as $codigo) {
            $e = new WhatsAppException('x', 400, $codigo);
            $this->assertTrue($e->afetaTodos(), $codigo);
            $this->assertFalse($e->transitoria(), $codigo);
        }

        $this->assertTrue((new WhatsAppException('x', 401))->afetaTodos());
        $this->assertTrue((new WhatsAppException('x', 402))->afetaTodos());
        $this->assertTrue((new WhatsAppException('sem resposta', 0))->afetaTodos());
    }

    public function test_erro_transitorio_nao_vira_sonda(): void
    {
        foreach (['130429', '4', '80007', '131016', 'timeout', 'timeout_envio'] as $codigo) {
            $e = new WhatsAppException('x', 400, $codigo);
            $this->assertTrue($e->transitoria(), $codigo);
            $this->assertFalse($e->afetaTodos(), $codigo);
        }

        $this->assertTrue((new WhatsAppException('x', 429))->transitoria());
        $this->assertTrue((new WhatsAppException('x', 503))->transitoria());
    }

    public function test_recusa_do_contato_nao_afeta_todos(): void
    {
        $semWhatsApp = new WhatsAppException('x', 400, '131026');
        $this->assertTrue($semWhatsApp->semWhatsApp());
        $this->assertFalse($semWhatsApp->afetaTodos());

        $marketing = new WhatsAppException('x', 400, '131050');
        $this->assertTrue($marketing->marketingParado());
        $this->assertFalse($marketing->afetaTodos());

        $janela = new WhatsAppException('x', 400, '131047');
        $this->assertTrue($janela->janelaFechada());
        $this->assertFalse($janela->afetaTodos());

        $template = new WhatsAppException('x', 400, '132000');
        $this->assertTrue($template->doTemplate());
        $this->assertFalse($template->afetaTodos());
    }

    public function test_traducao_explica_as_recusas_conhecidas(): void
    {
        $this->assertStringContainsString('pagamento', WhatsAppException::traduzir(400, '141006', ''));
        $this->assertStringContainsString('24h', WhatsAppException::traduzir(400, '131047', ''));
        $this->assertStringContainsString('sem WhatsApp', WhatsAppException::traduzir(400, '131026', ''));
        $this->assertStringContainsString('marketing', WhatsAppException::traduzir(400, null, 'marketing_preference_stopped'));
        $this->assertStringContainsString('Saldo', WhatsAppException::traduzir(402, null, ''));
        $this->assertSame('O provedor recusou.', WhatsAppException::traduzir(400, '999999', 'x'));
    }
}
