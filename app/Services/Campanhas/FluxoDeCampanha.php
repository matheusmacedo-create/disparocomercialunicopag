<?php

declare(strict_types=1);

namespace App\Services\Campanhas;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Services\Envio\Despachante;
use App\Services\Envio\Guardas;
use App\Services\Envio\TravaDoRemetente;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Permissions;
use App\Support\Telefone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * As transições de estado da campanha, com autorização NO SERVIDOR (esconder a ação no painel não
 * basta) e trilha (KB 2.2, 4.6):
 *
 *   rascunho ──simular──► (prévia válida) ──enviarParaAprovacao──► aguardando_aprovacao
 *   aguardando_aprovacao ──aprovar (outro usuário; ou auto-aprovação com espera e teto)──► aprovada
 *   aprovada ──iniciar──► em_andamento ⇄ pausar/retomar ──► concluida | cancelada
 *
 * Aprovar MATERIALIZA os destinatários (telefone e variáveis congelados) e grava o hash do que foi
 * aprovado; o executor devolve para aprovação se algo mudar. Qualquer edição volta a rascunho.
 */
final class FluxoDeCampanha
{
    public function __construct(
        private readonly MontadorDePublico $publico,
        private readonly Despachante $despachante,
    ) {}

    /** Prévia: valida, conta por motivo, grava `simulacao` + hash. Lança ValidationException com os problemas. */
    public function simular(Campaign $campaign, User $user): array
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_RASCUNHO, Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA]);
        $this->validar($campaign);

        $resumo = $this->publico->simular($campaign);

        $campaign->forceFill([
            'simulacao' => $resumo,
            'simulacao_hash' => $campaign->hashDoEnvio(),
            'custo_estimado' => $resumo['custo_estimado'] ?? null,
        ])->save();

        $campaign->registrarEvento('simulada', ['elegiveis' => $resumo['elegiveis'], 'excluidos' => $resumo['excluidos'], 'motivos' => $resumo['motivos'], 'custo_estimado' => $resumo['custo_estimado'] ?? null], $user->id);

        return $resumo;
    }

    public function enviarParaAprovacao(Campaign $campaign, User $user): void
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_RASCUNHO]);
        $this->validar($campaign);
        $this->exigirPreviaAtual($campaign);

        if ((int) ($campaign->simulacao['elegiveis'] ?? 0) <= 0) {
            throw ValidationException::withMessages(['publico' => 'A prévia não encontrou nenhum destinatário elegível: não há o que aprovar.']);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_AGUARDANDO_APROVACAO])->save();
        $campaign->registrarEvento('enviada_para_aprovacao', ['elegiveis' => $campaign->simulacao['elegiveis'] ?? null], $user->id);
    }

    /** Devolve para ajuste (quem aprova não gostou). */
    public function devolver(Campaign $campaign, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA]);

        $campaign->recipients()->delete();
        $campaign->forceFill(['status' => Campaign::STATUS_RASCUNHO, 'aprovado_por' => null, 'aprovado_em' => null, 'aprovacao_hash' => null, 'template_categoria' => null])->save();
        $campaign->registrarEvento('reprovada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    /**
     * Aprova: quatro olhos (quem criou não aprova), ou auto-aprovação explícita com espera obrigatória e
     * teto por campanha. Exige prévia posterior à última edição e o número de destinatários digitado.
     * Materializa os destinatários e congela categoria e hash.
     */
    public function aprovar(Campaign $campaign, User $user, int $destinatariosConfirmados, bool $autoAprovacao = false, ?string $observacao = null): array
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_AGUARDANDO_APROVACAO]);
        $this->validar($campaign);
        $this->exigirPreviaAtual($campaign);

        $elegiveis = (int) ($campaign->simulacao['elegiveis'] ?? 0);

        if ($destinatariosConfirmados !== $elegiveis) {
            throw ValidationException::withMessages(['destinatarios' => sprintf('Digite o número de destinatários da prévia (%d) para confirmar.', $elegiveis)]);
        }

        $mesmaPessoa = $campaign->criado_por !== null && $campaign->criado_por === $user->id;
        $espera = 0;

        if ($mesmaPessoa) {
            if (! $autoAprovacao) {
                throw ValidationException::withMessages(['aprovacao' => 'Quem criou a campanha não aprova a própria campanha. Peça a outro usuário, ou marque "auto-aprovação" (com espera obrigatória e teto por campanha).']);
            }

            $teto = (int) config('disparo.auto_aprovacao.teto_destinatarios', 200);

            if ($elegiveis > $teto) {
                throw ValidationException::withMessages(['aprovacao' => sprintf('Auto-aprovação só até %d destinatários; esta campanha tem %d. Peça a aprovação de outro usuário.', $teto, $elegiveis)]);
            }

            $espera = (int) config('disparo.auto_aprovacao.espera_minutos', 30);
        }

        $template = $this->template($campaign);
        $montagem = $this->publico->montar($campaign);

        $agendada = $campaign->agendada_para;
        if ($espera > 0 && ($agendada === null || $agendada->lt(now()->addMinutes($espera)))) {
            $agendada = now()->addMinutes($espera);
        }

        $campaign->forceFill([
            'status' => Campaign::STATUS_APROVADA,
            'aprovado_por' => $user->id,
            'aprovado_em' => now(),
            'template_categoria' => strtoupper((string) $template?->categoria),
            'agendada_para' => $agendada,
        ])->save();
        $campaign->forceFill(['aprovacao_hash' => $campaign->hashDaAprovacao($template)])->save();

        $campaign->registrarEvento('aprovada', [
            'destinatarios' => $montagem['criados'],
            'pulados_na_montagem' => $montagem['pulados'],
            'custo_estimado' => $campaign->custo_estimado,
            'categoria' => $campaign->template_categoria,
            'auto_aprovacao' => $mesmaPessoa,
            'espera_minutos' => $espera,
            'agendada_para' => $agendada?->toIso8601String(),
            'observacao' => $observacao !== null ? mb_substr($observacao, 0, 500) : null,
            'hash' => $campaign->aprovacao_hash,
        ], $user->id);

        return $montagem + ['agendada_para' => $agendada];
    }

    /** Inicia (ou agenda): a campanha passa a `em_andamento`; o executor respeita `agendada_para`. */
    public function iniciar(Campaign $campaign, User $user, bool $agora = false): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_APROVADA]);

        if (! $agora && $campaign->agendada_para !== null && $campaign->agendada_para->gt(now())) {
            $campaign->registrarEvento('iniciada', ['agendada_para' => $campaign->agendada_para->toIso8601String()], $user->id);

            return;
        }

        if ($agora && $campaign->agendada_para !== null && $campaign->agendada_para->gt(now()) && $campaign->aprovado_por === $campaign->criado_por) {
            throw ValidationException::withMessages(['inicio' => 'Campanha auto-aprovada só começa depois da espera obrigatória.']);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'iniciada_em' => now(), 'pausada_motivo' => null, 'agendada_para' => $agora ? null : $campaign->agendada_para])->save();
        $campaign->registrarEvento('iniciada', ['agora' => $agora], $user->id);
    }

    public function pausar(Campaign $campaign, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_APROVADA]);

        $campaign->forceFill(['status' => Campaign::STATUS_PAUSADA, 'pausada_motivo' => mb_substr('Pausada por '.$user->name.': '.$motivo, 0, 500)])->save();
        $campaign->registrarEvento('pausada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    /** Retomar é decisão de quem aprova (a pausa pode ter sido do disjuntor). Simulação volta a pendente. */
    public function retomar(Campaign $campaign, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::APPROVE_CAMPAIGNS);
        $this->exigirStatus($campaign, [Campaign::STATUS_PAUSADA]);

        if ($campaign->sender->bloqueadoPorQualidade()) {
            throw ValidationException::withMessages(['remetente' => 'O remetente está bloqueado por qualidade: religue-o em Configuração > Remetentes antes de retomar.']);
        }

        $campaign->recipients()->where('status', CampaignRecipient::STATUS_SIMULADO)->update(['status' => CampaignRecipient::STATUS_PENDENTE, 'motivo' => null, 'wamid' => null, 'whatsapp_message_id' => null, 'enviado_em' => null]);
        $campaign->forceFill(['status' => Campaign::STATUS_EM_ANDAMENTO, 'pausada_motivo' => null, 'iniciada_em' => $campaign->iniciada_em ?? now()])->save();
        $campaign->registrarEvento('retomada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    public function cancelar(Campaign $campaign, User $user, string $motivo): void
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);

        if (! $campaign->aberta()) {
            throw ValidationException::withMessages(['status' => 'Campanha já encerrada.']);
        }

        $campaign->forceFill(['status' => Campaign::STATUS_CANCELADA, 'pausada_motivo' => mb_substr($motivo, 0, 500), 'concluida_em' => now()])->save();
        $campaign->registrarEvento('cancelada', ['motivo' => mb_substr($motivo, 0, 500)], $user->id);
    }

    /** Cópia em rascunho (mesmo template, variáveis, público e regras); passa pela prévia e aprovação de novo. */
    public function duplicar(Campaign $campaign, User $user, ?array $publico = null, ?string $nome = null): Campaign
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);

        $nova = new Campaign($campaign->only(['nome', 'sender_id', 'objetivo', 'template_nome', 'template_idioma', 'prioridade', 'variaveis', 'publico', 'regras', 'observacoes']));
        $nova->nome = $nome ?? ($campaign->nome.' (cópia)');
        if ($publico !== null) {
            $nova->publico = $publico;
        }
        $nova->forceFill(['status' => Campaign::STATUS_RASCUNHO, 'criado_por' => $user->id])->save();
        $nova->registrarEvento('duplicada', ['de' => $campaign->id], $user->id);
        $campaign->registrarEvento('duplicada', ['para' => $nova->id], $user->id);

        return $nova;
    }

    /** Campanha filha com quem falhou por erro transitório ou ficou `enviado_incerto`/`falhou` nesta. */
    public function reenviarFalhas(Campaign $campaign, User $user): Campaign
    {
        return $this->duplicar($campaign, $user, [
            'campanha_anterior' => ['campaign_id' => $campaign->id, 'status' => [CampaignRecipient::STATUS_FALHOU]],
        ], $campaign->nome.' (reenvio de falhas)');
    }

    /**
     * Enviar teste: só para o telefone verificado do próprio usuário, poucas vezes por dia, fora dos totais
     * e dos destinatários; ignora conversa aberta e frequência, respeita remetente, qualidade, janela e teto.
     *
     * @return array{0: WhatsappMessage, 1: ?WhatsAppException}
     */
    public function enviarTeste(Campaign $campaign, User $user): array
    {
        $this->autorizar($user, Permissions::MANAGE_CAMPAIGNS);
        $this->validar($campaign);

        $telefone = Telefone::normalizar($user->telefone_teste);

        if ($telefone === null) {
            throw ValidationException::withMessages(['telefone' => 'Cadastre o seu telefone de teste no perfil (Sistema > Usuários) antes de enviar teste.']);
        }

        $sender = $campaign->sender;
        $template = $this->template($campaign);

        if (($motivo = Guardas::motivoDoRemetente($sender, $template)) !== null) {
            throw ValidationException::withMessages(['remetente' => 'O remetente não pode enviar agora: '.$motivo]);
        }

        $hoje = WhatsappMessage::query()->where('sender_id', $sender->id)->where('telefone', $telefone)->where('origem', WhatsappMessage::ORIGEM_MANUAL)->where('user_id', $user->id)->where('enviado_em', '>=', now()->startOfDay())->count();

        if ($hoje >= (int) config('disparo.teste.max_por_dia', 5)) {
            throw ValidationException::withMessages(['teste' => 'Limite de testes por dia atingido para o seu telefone.']);
        }

        $contato = Contact::query()->porTelefone($telefone)->first() ?? new Contact(['telefone' => $telefone, 'nome' => $user->name, 'origem' => 'manual', 'tipo' => 'outro']);

        if (! $contato->exists) {
            $contato->save();
        }

        if (\App\Models\Suppression::bloqueia($contato->telefone, $sender, $template?->categoria) !== null) {
            throw ValidationException::withMessages(['teste' => 'O seu telefone de teste está bloqueado (pediu para parar).']);
        }

        $resultado = TravaDoRemetente::executar($sender, fn (): array => $this->despachante->enviarTemplate(
            $sender, $contato, $template, (array) ($campaign->variaveis ?? []), WhatsappMessage::ORIGEM_MANUAL, ['user_id' => $user->id], ! config('disparo.envio_habilitado'),
        ), 5);

        if ($resultado === false) {
            throw ValidationException::withMessages(['teste' => 'O remetente está ocupado com uma rodada: tente de novo em instantes.']);
        }

        [$mensagem, $excecao] = $resultado;
        $campaign->registrarEvento('teste_enviado', ['mensagem_id' => $mensagem->id, 'ok' => $excecao === null, 'erro' => $excecao?->getMessage()], $user->id);

        return $resultado;
    }

    /** Edição de campo que define o envio invalida prévia e aprovação: volta a rascunho. */
    public function aoEditar(Campaign $campaign): void
    {
        if ($campaign->simulacao_hash !== null && $campaign->simulacao_hash !== $campaign->hashDoEnvio()) {
            if (in_array($campaign->status, [Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA], true)) {
                $campaign->recipients()->delete();
                $campaign->forceFill(['status' => Campaign::STATUS_RASCUNHO, 'aprovado_por' => null, 'aprovado_em' => null, 'aprovacao_hash' => null, 'template_categoria' => null])->save();
                $campaign->registrarEvento('aprovacao_invalidada', ['motivo' => 'campanha editada depois da prévia/aprovação'], auth()->id());
            }
        }
    }

    public function template(Campaign $campaign): ?MessageTemplate
    {
        return MessageTemplate::query()
            ->where('sender_id', $campaign->sender_id)
            ->where('nome', $campaign->template_nome)
            ->where('idioma', $campaign->template_idioma)
            ->first();
    }

    private function validar(Campaign $campaign): void
    {
        $problemas = ValidadorDeCampanha::problemas($campaign);

        if ($problemas !== []) {
            throw ValidationException::withMessages(['campanha' => $problemas]);
        }
    }

    private function exigirPreviaAtual(Campaign $campaign): void
    {
        if (! $campaign->previaAtual()) {
            throw ValidationException::withMessages(['previa' => 'Gere a prévia depois da última edição: a aprovação só vale com a prévia atual.']);
        }
    }

    /** @param  list<string>  $permitidos */
    private function exigirStatus(Campaign $campaign, array $permitidos): void
    {
        if (! in_array($campaign->status, $permitidos, true)) {
            throw ValidationException::withMessages(['status' => sprintf('A campanha está "%s"; esta ação vale para: %s.', Campaign::statusLabels()[$campaign->status] ?? $campaign->status, implode(', ', array_map(fn ($s) => Campaign::statusLabels()[$s] ?? $s, $permitidos)))]);
        }
    }

    private function autorizar(User $user, string $permissao): void
    {
        if (! $user->can($permissao)) {
            throw new AuthorizationException('Sem permissão: '.$permissao);
        }
    }
}
