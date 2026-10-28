<?php

declare(strict_types=1);

namespace App\Services\Reguas;

use App\Models\Contact;
use App\Models\Journey;
use App\Models\JourneyStep;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Mesma máquina de estados da campanha para a régua (achado de 28/10: régua sem aprovação era uma campanha
 * sem quatro olhos): rascunho → aguardando_aprovacao → aprovada → ativa ⇄ pausada. Qualquer edição de
 * passo, variável ou gatilho volta a rascunho e desativa.
 */
final class FluxoDeRegua
{
    /** @return list<string> */
    public function problemas(Journey $journey): array
    {
        $problemas = [];
        $sender = $journey->sender;

        if ($sender === null) {
            return ['Escolha o remetente.'];
        }

        $passos = $journey->steps()->get();

        if ($passos->isEmpty()) {
            $problemas[] = 'A régua precisa de pelo menos um passo.';
        }

        if ((int) $journey->max_por_dia <= 0) {
            $problemas[] = 'Defina o máximo de envios por dia da régua.';
        }

        if ($journey->gatilho !== Journey::GATILHO_MANUAL && (int) $journey->gatilho_ref <= 0) {
            $problemas[] = 'Gatilho por base, tag ou segmento precisa dizer qual.';
        }

        foreach ($passos as $passo) {
            if ($passo->tipo === JourneyStep::TIPO_TEMPLATE) {
                $template = MessageTemplate::query()->where('sender_id', $sender->id)->where('nome', (string) $passo->template_nome)->where('idioma', (string) ($passo->template_idioma ?: 'pt_BR'))->first();

                if ($template === null) {
                    $problemas[] = sprintf('Passo %d: template "%s" não está no cache do remetente.', $passo->ordem, $passo->template_nome);
                } elseif (($motivo = $template->motivoNaoEnviavel($sender)) !== null) {
                    $problemas[] = sprintf('Passo %d: %s', $passo->ordem, $motivo);
                }
            }

            if ($passo->tipo === JourneyStep::TIPO_AGUARDAR && (int) $passo->aguardar_horas <= 0) {
                $problemas[] = sprintf('Passo %d: informe quantas horas aguardar.', $passo->ordem);
            }

            if ($passo->tipo === JourneyStep::TIPO_TEXTO && trim((string) (($passo->variaveis ?? [])['texto'] ?? '')) === '') {
                $problemas[] = sprintf('Passo %d: informe o texto que vai na resposta.', $passo->ordem);
            }

            if ($passo->tipo === JourneyStep::TIPO_TAG && (int) $passo->tag_id <= 0) {
                $problemas[] = sprintf('Passo %d: escolha a tag.', $passo->ordem);
            }
        }

        return $problemas;
    }

    public function enviarParaAprovacao(Journey $journey, User $user): void
    {
        $this->autorizar($user, Permissions::MANAGE_JOURNEYS);
        $this->exigirStatus($journey, [Journey::STATUS_RASCUNHO]);
        $this->validar($journey);

        $journey->forceFill(['status' => Journey::STATUS_AGUARDANDO_APROVACAO])->save();
        $journey->registrarEvento('enviada_para_aprovacao', null, $user->id);
    }

    public function devolver(Journey $journey, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $journey->forceFill(['status' => Journey::STATUS_RASCUNHO, 'ativa' => false, 'aprovado_por' => null, 'aprovado_em' => null])->save();
        $journey->registrarEvento('reprovada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    /** Quem criou não aprova a própria régua (sem exceção: a régua roda todo dia, sozinha). */
    public function aprovar(Journey $journey, User $user, ?string $observacao = null): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($journey, [Journey::STATUS_AGUARDANDO_APROVACAO]);
        $this->validar($journey);

        if ($journey->criado_por !== null && $journey->criado_por === $user->id && ! $user->hasRole(Permissions::ROLE_SUPER_ADMIN)) {
            throw ValidationException::withMessages(['aprovacao' => 'Quem criou a régua não aprova a própria régua.']);
        }

        $journey->forceFill(['status' => Journey::STATUS_APROVADA, 'aprovado_por' => $user->id, 'aprovado_em' => now()])->save();
        $journey->registrarEvento('aprovada', ['observacao' => $observacao, 'passos' => $journey->steps()->count(), 'max_por_dia' => $journey->max_por_dia], $user->id);
    }

    public function ativar(Journey $journey, User $user): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($journey, [Journey::STATUS_APROVADA, Journey::STATUS_PAUSADA]);

        $journey->forceFill(['status' => Journey::STATUS_ATIVA, 'ativa' => true])->save();
        $journey->registrarEvento('ativada', null, $user->id);
    }

    public function pausar(Journey $journey, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::MANAGE_JOURNEYS);
        $this->exigirStatus($journey, [Journey::STATUS_ATIVA]);

        $journey->forceFill(['status' => Journey::STATUS_PAUSADA, 'ativa' => false])->save();
        $journey->registrarEvento('pausada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    /** Edição de passo, variável, gatilho ou remetente: volta a rascunho e desativa. */
    public function aoEditar(Journey $journey, ?int $userId = null): void
    {
        if ($journey->status !== Journey::STATUS_RASCUNHO) {
            $journey->forceFill(['status' => Journey::STATUS_RASCUNHO, 'ativa' => false, 'aprovado_por' => null, 'aprovado_em' => null])->save();
            $journey->registrarEvento('editada', ['efeito' => 'volta a rascunho e desativa'], $userId ?? auth()->id());
        }
    }

    /** Inscrição manual pelo painel (régua ativa). */
    public function inscrever(Journey $journey, Contact $contato, User $user): bool
    {
        $this->autorizar($user, Permissions::MANAGE_JOURNEYS);

        if ($journey->status !== Journey::STATUS_ATIVA) {
            throw ValidationException::withMessages(['status' => 'Só régua ativa recebe inscrições.']);
        }

        $inscricao = $journey->inscrever($contato, 'manual', $user->id);

        if ($inscricao !== null) {
            $journey->registrarEvento('inscricao_manual', ['contact_id' => $contato->id, 'inscricao_id' => $inscricao->id], $user->id);
        }

        return $inscricao !== null;
    }

    private function validar(Journey $journey): void
    {
        $problemas = $this->problemas($journey);

        if ($problemas !== []) {
            throw ValidationException::withMessages(['regua' => $problemas]);
        }
    }

    /** @param  list<string>  $permitidos */
    private function exigirStatus(Journey $journey, array $permitidos): void
    {
        if (! in_array($journey->status, $permitidos, true)) {
            throw ValidationException::withMessages(['status' => sprintf('A régua está "%s"; esta ação vale para: %s.', Journey::statusLabels()[$journey->status] ?? $journey->status, implode(', ', array_map(fn ($s) => Journey::statusLabels()[$s] ?? $s, $permitidos)))]);
        }
    }

    private function autorizar(User $user, string $permissao): void
    {
        if (! $user->can($permissao)) {
            throw new AuthorizationException('Sem permissão: '.$permissao);
        }
    }
}
