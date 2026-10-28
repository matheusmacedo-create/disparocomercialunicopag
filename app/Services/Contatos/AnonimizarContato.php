<?php

declare(strict_types=1);

namespace App\Services\Contatos;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\JourneyEnrollment;
use App\Models\Suppression;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Support\Permissions;
use App\Support\Telefone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Direito de exclusão (LGPD, Art. 18; KB 2.13). Anonimizar de verdade - não só apagar nome e e-mail
 * (achado de 28/10: o telefone ficava em três tabelas e o CSV em claro):
 *
 *  1. a supressão `anonimizado` (escopo tudo) é gravada ANTES de mexer no telefone, pelo hash do número
 *     real - é ela que garante que o número nunca mais recebe nada, mesmo reimportado;
 *  2. o telefone vira o marcador `anon:<12 hex do hash>` em contacts, whatsapp_messages e
 *     campaign_recipients; em suppressions fica nulo (o hash permanece);
 *  3. nome, primeiro_nome, e-mail, documento (e hash), empresa, observações, atributos, ids da Kapso e
 *     consentimento são zerados; o `conteudo`/`payload` das mensagens e as `variaveis` dos destinatários
 *     são apagados (do payload sobra só a categoria do template, que não é dado pessoal e sustenta teto
 *     e frequência);
 *  4. inscrições ativas em régua encerram; a trilha `contact_events` (pelo hash) guarda quem, quando e
 *     por quê.
 *
 * `telefone_hash` do contato NÃO muda (o hook do model preserva o hash quando o telefone é um marcador).
 */
final class AnonimizarContato
{
    /** Pelo painel: exige `anonymize contacts`. */
    public function executar(Contact $contato, User $user, string $motivo): void
    {
        if (! $user->can(Permissions::ANONYMIZE_CONTACTS)) {
            throw new AuthorizationException('Anonimizar contato exige a permissão "'.Permissions::ANONYMIZE_CONTACTS.'".');
        }

        $this->anonimizar($contato, $user->id, $motivo);
    }

    /** Pela rotina de retenção (ator `sistema`). */
    public function pelaRotina(Contact $contato, string $motivo): void
    {
        $this->anonimizar($contato, null, $motivo);
    }

    private function anonimizar(Contact $contato, ?int $userId, string $motivo): void
    {
        if ($contato->anonimizado() || Telefone::anonimizado($contato->telefone)) {
            return;
        }

        $motivo = mb_substr(trim($motivo), 0, 500);

        if ($motivo === '') {
            throw new \InvalidArgumentException('Informe o motivo da anonimização (pedido do titular, retenção...).');
        }

        DB::transaction(function () use ($contato, $userId, $motivo): void {
            $telefone = $contato->telefone;
            $this->limparAmostraDasPrevias($contato);
            $hash = Telefone::hash($telefone);
            $marcador = Telefone::anonimo($telefone);
            $formas = Telefone::formas($telefone);
            $formas[] = $telefone;

            // O webhook da Meta às vezes grava o celular SEM o nono dígito (+552198765432): essa forma
            // também é do titular (fixo nunca começa com 9), e tem de sumir junto.
            $digitos = ltrim($telefone, '+');

            if (strlen($digitos) === 13 && $digitos[4] === '9') {
                $formas[] = '+'.substr($digitos, 0, 4).substr($digitos, 5);
            }

            // 1. Supressão pelo hash do número real, antes de trocar o telefone (createOrFirst: se já há
            //    uma de escopo `tudo` vigente, ela fica).
            Suppression::suprimir($telefone, Suppression::MOTIVO_ANONIMIZADO, 'anonimizacao', null, Suppression::ESCOPO_TUDO, null, 'direito de exclusão: '.$motivo, $userId);
            Suppression::query()->where('telefone_hash', $hash)->update(['telefone' => null]);

            // 2. Trilha de mensagens: o número e o texto somem; o metadado (direção, template, status) fica.
            foreach (WhatsappMessage::query()->where(fn ($q) => $q->where('contact_id', $contato->id)->orWhereIn('telefone', array_unique($formas)))->cursor() as $mensagem) {
                $categoria = ((array) ($mensagem->payload ?? []))['categoria'] ?? null;
                $mensagem->forceFill([
                    'telefone' => $marcador,
                    'contact_id' => $contato->id,
                    'conteudo' => null,
                    'payload' => $categoria !== null ? ['categoria' => $categoria] : null,
                ])->saveQuietly();
            }

            CampaignRecipient::query()->where('contact_id', $contato->id)->update(['telefone' => $marcador, 'variaveis' => null]);

            // 3. Régua: quem foi anonimizado não recebe mais nada.
            foreach (JourneyEnrollment::query()->where('contact_id', $contato->id)->where('status', JourneyEnrollment::STATUS_ATIVA)->get() as $inscricao) {
                $inscricao->encerrar(JourneyEnrollment::STATUS_SAIU, 'anonimizado');
            }

            // 4. O contato em si.
            $contato->forceFill([
                'telefone' => $marcador,
                'nome' => null,
                'primeiro_nome' => null,
                'nome_origem' => null,
                'email' => null,
                'documento' => null,
                'documento_hash' => null,
                'empresa' => null,
                'observacoes' => null,
                'atributos' => null,
                'kapso_contact_id' => null,
                'kapso_conversation_id' => null,
                'consentimento_origem' => null,
                'consentimento_em' => null,
                'consentimento_canal' => null,
                'consentimento_detalhes' => null,
                'status' => Contact::STATUS_ANONIMIZADO,
                'anonimizado_em' => now(),
            ])->save();

            ContactEvent::registrar($hash, 'anonimizado', ['motivo' => $motivo], $userId, $contato->id);
        });
    }

    /** A prévia gravada na campanha guarda nome e variáveis de até 5 contatos: tira as linhas deste. */
    private function limparAmostraDasPrevias(Contact $contato): void
    {
        $rotulo = $contato->rotulo();

        foreach (Campaign::query()->whereNotNull('simulacao')->get() as $campanha) {
            $simulacao = (array) $campanha->simulacao;
            $amostra = array_values(array_filter((array) ($simulacao['amostra'] ?? []), fn (mixed $linha): bool => ! is_array($linha) || (string) ($linha['contato'] ?? '') !== $rotulo));

            if (count($amostra) !== count((array) ($simulacao['amostra'] ?? []))) {
                $simulacao['amostra'] = $amostra;
                $campanha->forceFill(['simulacao' => $simulacao])->save();
            }
        }
    }
}
