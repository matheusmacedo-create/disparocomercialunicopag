<?php

declare(strict_types=1);

namespace App\Services\Envio;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\MessageTemplate;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\WhatsAppClientFactory;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Telefone;
use App\Support\Variaveis;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Um envio a um contato, com registro na trilha (KB 4.7) e as consequências da recusa no contato
 * (131026 → sem WhatsApp; 131050 → parou marketing; 131049 → carência). Nunca lança WhatsAppException:
 * o resultado está na WhatsappMessage devolvida (`status` sent/failed/pending, `erro` traduzido) - a
 * exceção vai junto para quem decide o disjuntor.
 *
 * A linha da mensagem é gravada como `pending` ANTES do POST: o webhook da Kapso pode chegar antes de o
 * processo gravar o wamid (achado da revisão de 28/10), e um processo que morre entre o POST e o save
 * deixa rastro em vez de template em dobro.
 *
 * Nada de PII no `payload` (só categoria, template, ids); os valores renderizados ficam em `conteudo`
 * (atrás de `view pii`) e em `campaign_recipients.variaveis`.
 */
final class Despachante
{
    public function __construct(private readonly WhatsAppClientFactory $clientes) {}

    /**
     * @param  array<string, mixed>  $variaveis  da campanha/passo ({@see MontadorDeComponentes})
     * @param  array<string, mixed>  $extra  campos a mais da mensagem (campaign_recipient_id, journey_enrollment_id...)
     * @return array{0: WhatsappMessage, 1: ?WhatsAppException}
     */
    public function enviarTemplate(Sender $sender, Contact $contato, MessageTemplate $template, array $variaveis, string $origem, array $extra = [], bool $simular = false): array
    {
        $para = Telefone::paraEnvio($contato->telefone);
        $categoria = strtoupper((string) $template->categoria);

        try {
            if ($para === null) {
                throw new WhatsAppException('Telefone não parece um celular brasileiro válido para WhatsApp.', 0, 'telefone_invalido');
            }

            $componentes = MontadorDeComponentes::montar($template->definicao(), $variaveis, Variaveis::doContato($contato));
            $resumo = MontadorDeComponentes::resumo($template->nome, $componentes);
        } catch (WhatsAppException $exception) {
            // Recusa local (variável vazia, link, telefone): nada foi ao provedor.
            $mensagem = $this->registrar($sender, $contato, $origem, $extra + [
                'tipo' => WhatsappMessage::TIPO_TEMPLATE,
                'template_nome' => $template->nome,
                'status' => WhatsappMessage::STATUS_FAILED,
                'codigo_erro' => mb_substr((string) $exception->codigo, 0, 40),
                'erro' => mb_substr($exception->getMessage(), 0, 1000),
                'payload' => ['classe' => 'local', 'categoria' => $categoria],
            ]);

            return [$mensagem, $exception];
        }

        if ($simular) {
            $mensagem = $this->registrar($sender, $contato, $origem, $extra + [
                'tipo' => WhatsappMessage::TIPO_TEMPLATE,
                'template_nome' => $template->nome,
                'conteudo' => $resumo,
                // Simulado: não conta em teto, frequência nem conversa aberta (failed fica fora das contagens).
                'status' => WhatsappMessage::STATUS_FAILED,
                'codigo_erro' => 'simulado',
                'payload' => ['simulado' => true, 'categoria' => $categoria],
            ]);

            return [$mensagem, null];
        }

        // Antes do POST: a linha existe para o webhook achar e para um processo morto deixar rastro.
        $mensagem = $this->registrar($sender, $contato, $origem, $extra + [
            'tipo' => WhatsappMessage::TIPO_TEMPLATE,
            'template_nome' => $template->nome,
            'conteudo' => $resumo,
            'status' => WhatsappMessage::STATUS_PENDING,
            'payload' => ['categoria' => $categoria],
        ]);

        try {
            $wamid = $this->clientes->para($sender)->enviarTemplate($para, $template->nome, $template->idioma, $componentes);
        } catch (WhatsAppException $exception) {
            $incerto = (string) $exception->codigo === 'timeout_envio';

            // Timeout depois do POST: a mensagem PODE ter saído. Fica `pending` para contar em teto,
            // frequência e conversa aberta (reenviar às cegas duplicaria); o webhook corrige se chegar.
            $mensagem->forceFill([
                'status' => $incerto ? WhatsappMessage::STATUS_PENDING : WhatsappMessage::STATUS_FAILED,
                'codigo_erro' => mb_substr((string) $exception->codigo, 0, 40),
                'erro' => mb_substr($exception->getMessage(), 0, 1000),
                'payload' => ['classe' => $exception->classe(), 'timeout_envio' => $incerto, 'categoria' => $categoria],
            ])->save();

            $this->aplicarConsequencias($sender, $contato, $exception, null);

            if ($incerto) {
                $contato->forceFill(['ultimo_envio_em' => now(), 'ultimo_template_em' => now()])->save();
            } else {
                $contato->increment('falhas_total');
            }

            return [$mensagem, $exception];
        }

        $mensagem = $this->confirmar($mensagem, $wamid);

        $contato->increment('envios_total');
        $contato->forceFill(['ultimo_envio_em' => now(), 'ultimo_template_em' => now()])->save();

        return [$mensagem, null];
    }

    /**
     * Texto livre (só dentro da janela de 24 h): resposta do painel a quem respondeu, com autor.
     *
     * @return array{0: WhatsappMessage, 1: ?WhatsAppException}
     */
    public function enviarTexto(Sender $sender, Contact $contato, string $texto, string $origem, array $extra = [], ?int $userId = null): array
    {
        $texto = trim($texto);

        $mensagem = $this->registrar($sender, $contato, $origem, $extra + [
            'tipo' => WhatsappMessage::TIPO_TEXTO,
            'conteudo' => mb_substr($texto, 0, 4000),
            'status' => WhatsappMessage::STATUS_PENDING,
            'user_id' => $userId,
        ]);

        try {
            $para = Telefone::paraEnvio($contato->telefone) ?? throw new WhatsAppException('Telefone inválido para WhatsApp.', 0, 'telefone_invalido');

            if ($texto === '') {
                throw new WhatsAppException('Mensagem sem texto.', 0, 'sem_texto');
            }

            $wamid = $this->clientes->para($sender)->enviarTexto($para, $texto);
        } catch (WhatsAppException $exception) {
            $mensagem->forceFill([
                'status' => (string) $exception->codigo === 'timeout_envio' ? WhatsappMessage::STATUS_PENDING : WhatsappMessage::STATUS_FAILED,
                'codigo_erro' => mb_substr((string) $exception->codigo, 0, 40),
                'erro' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            return [$mensagem, $exception];
        }

        $mensagem = $this->confirmar($mensagem, $wamid);
        $contato->increment('envios_total');
        $contato->forceFill(['ultimo_envio_em' => now()])->save();

        if ($userId !== null) {
            ContactEvent::registrar($contato->telefone_hash, 'texto_enviado', ['mensagem_id' => $mensagem->id, 'sender_id' => $sender->id], $userId, $contato->id);
        }

        return [$mensagem, null];
    }

    /**
     * O que a recusa diz sobre o contato: sem WhatsApp (quarentena), parou marketing na Meta (supressão de
     * marketing) ou a Meta limitou marketing a ele por ora (131049: carência, nunca supressão).
     */
    public function aplicarConsequencias(Sender $sender, Contact $contato, WhatsAppException $exception, ?string $wamid = null): void
    {
        if ($exception->semWhatsApp()) {
            $contato->marcarSemWhatsApp();
        }

        if ($exception->carenciaDeMarketing()) {
            $contato->marcarCarenciaDeMarketing();
        }

        if ($exception->marketingParado()) {
            Suppression::suprimir(
                $contato->telefone,
                Suppression::MOTIVO_META_MARKETING_STOPPED,
                'webhook',
                $sender->id,
                Suppression::ESCOPO_MARKETING,
                $wamid,
                mb_substr($exception->getMessage(), 0, 500),
            );
        }
    }

    /**
     * O POST foi aceito: grava o wamid. Se o webhook chegou antes e já criou a linha com este wamid
     * (`origem=webhook`), ela é a que vale: herda o vínculo com a campanha/régua e a nossa some.
     */
    private function confirmar(WhatsappMessage $mensagem, string $wamid): WhatsappMessage
    {
        try {
            $mensagem->forceFill(['wamid' => $wamid, 'status' => WhatsappMessage::STATUS_SENT, 'enviado_em' => now(), 'codigo_erro' => null, 'erro' => null])->save();

            return $mensagem;
        } catch (UniqueConstraintViolationException) {
            $existente = WhatsappMessage::query()->where('wamid', $wamid)->first();

            if ($existente === null) {
                throw new \RuntimeException('wamid duplicado sem linha correspondente: '.$wamid);
            }

            $existente->forceFill(array_filter([
                'contact_id' => $mensagem->contact_id,
                'origem' => $mensagem->origem,
                'campaign_recipient_id' => $mensagem->campaign_recipient_id,
                'journey_enrollment_id' => $mensagem->journey_enrollment_id,
                'conteudo' => $mensagem->conteudo,
                'template_nome' => $mensagem->template_nome,
                'tipo' => $mensagem->tipo,
                'user_id' => $mensagem->user_id,
                'payload' => array_merge((array) ($existente->payload ?? []), (array) ($mensagem->payload ?? [])),
            ], fn ($v): bool => $v !== null))->save();

            // Entrega que o webhook já tinha avançado fica como está (só avança).
            if (WhatsappMessage::rank($existente->status) < WhatsappMessage::rank(WhatsappMessage::STATUS_SENT) && $existente->status !== WhatsappMessage::STATUS_FAILED) {
                $existente->forceFill(['status' => WhatsappMessage::STATUS_SENT])->save();
            }

            $mensagem->delete();

            return $existente;
        }
    }

    /** @param  array<string, mixed>  $campos */
    private function registrar(Sender $sender, Contact $contato, string $origem, array $campos): WhatsappMessage
    {
        return WhatsappMessage::query()->create($campos + [
            'sender_id' => $sender->id,
            'contact_id' => $contato->id,
            'telefone' => $contato->telefone,
            'direcao' => WhatsappMessage::OUTBOUND,
            'origem' => $origem,
            'enviado_em' => now(),
        ]);
    }
}
