<?php

namespace App\Filament\Resources\Campaigns\Support;

use App\Filament\Resources\Campaigns\CampaignResource;
use App\Filament\Support\Fluxo;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Services\Campanhas\FluxoDeCampanha;
use App\Services\WhatsApp\WhatsAppException;
use App\Support\Mask;
use App\Support\Permissions;
use App\Support\Telefone;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

/**
 * Ações da ficha da campanha. A regra mora no {@see FluxoDeCampanha} (autorização no servidor, trilha,
 * transições); aqui só a tela: visibilidade por status e permissão, modal e aviso ao operador.
 */
class CampaignActions
{
    public static function gerarPrevia(): Action
    {
        return Action::make('gerar_previa')
            ->label('Gerar prévia')
            ->icon(Heroicon::OutlinedEye)
            ->color('gray')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && in_array(self::campanha($livewire)->status, [Campaign::STATUS_RASCUNHO, Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA], true))
            ->action(function (ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $resumo = [];

                if (Fluxo::tentar(function () use ($fluxo, $campanha, &$resumo): void {
                    $resumo = $fluxo->simular($campanha, self::usuario());
                })) {
                    Notification::make()
                        ->success()
                        ->title(sprintf('Prévia: %d elegíveis, %d excluídos', (int) ($resumo['elegiveis'] ?? 0), (int) ($resumo['excluidos'] ?? 0)))
                        ->body(self::linhaDeCusto($resumo))
                        ->send();
                }

                $campanha->refresh();
            });
    }

    public static function enviarTeste(): Action
    {
        return Action::make('enviar_teste')
            ->label('Enviar teste para mim')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->requiresConfirmation()
            ->modalHeading('Enviar teste')
            ->modalDescription(function (): string {
                $telefone = Telefone::normalizar(self::usuario()->telefone_teste);

                return $telefone === null
                    ? 'Você ainda não tem telefone de teste cadastrado (Sistema > Usuários). Sem ele o teste não sai.'
                    : sprintf('O template vai para o seu telefone de teste %s, com as variáveis da campanha (origem "manual": não entra nos totais nem nos destinatários). Respeita remetente pausado, qualidade, janela e teto; ignora conversa aberta e frequência.', Telefone::mascarar($telefone));
            })
            ->modalSubmitActionLabel('Enviar teste')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && in_array(self::campanha($livewire)->status, [Campaign::STATUS_RASCUNHO, Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA], true))
            ->action(function (ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $excecao = null;

                if (! Fluxo::tentar(function () use ($fluxo, $campanha, &$excecao): void {
                    [, $excecao] = $fluxo->enviarTeste($campanha, self::usuario());
                })) {
                    return;
                }

                if ($excecao instanceof WhatsAppException) {
                    Notification::make()->warning()->title('O teste não saiu')->body(Mask::scrub($excecao->getMessage()))->persistent()->send();
                } elseif (! config('disparo.envio_habilitado')) {
                    Notification::make()->info()->title('Teste simulado')->body('Ambiente em SIMULAÇÃO: o teste foi registrado, nada chegou ao celular.')->send();
                } else {
                    Notification::make()->success()->title('Teste enviado')->body('Confira o template no seu celular antes de mandar para a base.')->send();
                }
            });
    }

    public static function enviarParaAprovacao(): Action
    {
        return Action::make('enviar_para_aprovacao')
            ->label('Enviar para aprovação')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(fn (ViewRecord $livewire): string => self::resumoDaPrevia(self::campanha($livewire)).' Depois disto a campanha só muda voltando a rascunho.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && self::campanha($livewire)->status === Campaign::STATUS_RASCUNHO)
            ->action(function (ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);

                if (Fluxo::tentar(fn () => $fluxo->enviarParaAprovacao($campanha, self::usuario()))) {
                    Notification::make()->success()->title('Campanha aguardando aprovação')->send();
                }

                $campanha->refresh();
            });
    }

    public static function aprovar(): Action
    {
        return Action::make('aprovar')
            ->label('Aprovar')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->modalHeading('Aprovar campanha')
            ->modalDescription(fn (ViewRecord $livewire): string => self::resumoDaPrevia(self::campanha($livewire)))
            ->modalSubmitActionLabel('Aprovar')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && self::campanha($livewire)->status === Campaign::STATUS_AGUARDANDO_APROVACAO)
            ->schema([
                TextInput::make('destinatarios')
                    ->label('Digite o número de destinatários')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->helperText('O total de elegíveis da prévia. Digitar o número é a segunda leitura: é o que separa aprovar de clicar.'),
                Toggle::make('auto_aprovacao')
                    ->label('Auto-aprovação: esta campanha é minha')
                    ->visible(fn (ViewRecord $livewire): bool => self::campanha($livewire)->criado_por === self::usuario()->id)
                    ->helperText(sprintf(
                        'Quem criou não aprova a própria campanha. Marcando aqui, ela só começa depois de %d min de espera e só com até %d destinatários; acima disso, peça a outro usuário.',
                        (int) config('disparo.auto_aprovacao.espera_minutos', 30),
                        (int) config('disparo.auto_aprovacao.teto_destinatarios', 200),
                    )),
                Textarea::make('observacao')->label('Observação')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $montagem = [];

                if (Fluxo::tentar(function () use ($fluxo, $campanha, $data, &$montagem): void {
                    $montagem = $fluxo->aprovar(
                        $campanha,
                        self::usuario(),
                        (int) ($data['destinatarios'] ?? 0),
                        (bool) ($data['auto_aprovacao'] ?? false),
                        filled($data['observacao'] ?? null) ? (string) $data['observacao'] : null,
                    );
                })) {
                    $agendada = $montagem['agendada_para'] ?? null;

                    Notification::make()
                        ->success()
                        ->title(sprintf('Campanha aprovada: %d destinatário(s) congelados', (int) ($montagem['criados'] ?? 0)))
                        ->body($agendada !== null
                            ? 'Pode começar a partir de '.$agendada->timezone('America/Sao_Paulo')->format('d/m/Y H:i').'. Use "Iniciar" para colocar na fila.'
                            : 'Use "Iniciar" para colocar na fila do remetente.')
                        ->send();
                }

                $campanha->refresh();
            });
    }

    public static function devolver(): Action
    {
        return Action::make('devolver')
            ->label('Devolver para ajuste')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('warning')
            ->modalDescription('A campanha volta a rascunho e perde a aprovação; os destinatários congelados são descartados.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && in_array(self::campanha($livewire)->status, [Campaign::STATUS_AGUARDANDO_APROVACAO, Campaign::STATUS_APROVADA], true))
            ->schema([
                Textarea::make('motivo')->label('O que precisa mudar')->required()->minLength(5)->maxLength(500)->rows(3),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);

                if (Fluxo::tentar(fn () => $fluxo->devolver($campanha, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Campanha devolvida para ajuste')->send();
                }

                $campanha->refresh();
            });
    }

    public static function iniciar(): Action
    {
        return Action::make('iniciar')
            ->label('Iniciar')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->modalHeading('Iniciar campanha')
            ->modalDescription('A campanha entra na fila do remetente e sai no ritmo configurado, dentro da janela de horário. Campanha de marketing sai em levas: a primeira é pequena e espera os webhooks.')
            ->modalSubmitActionLabel('Iniciar')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && self::campanha($livewire)->status === Campaign::STATUS_APROVADA)
            ->schema([
                Radio::make('quando')
                    ->label('Quando')
                    ->options(function (ViewRecord $livewire): array {
                        $campanha = self::campanha($livewire);
                        $opcoes = ['agora' => 'Agora (respeitando a janela do remetente)'];

                        if ($campanha->agendada_para !== null && $campanha->agendada_para->isFuture()) {
                            $opcoes['agendado'] = 'No horário agendado: '.$campanha->agendada_para->timezone('America/Sao_Paulo')->format('d/m/Y H:i');
                        }

                        return $opcoes;
                    })
                    ->default(fn (ViewRecord $livewire): string => self::campanha($livewire)->agendada_para?->isFuture() ? 'agendado' : 'agora')
                    ->required(),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $agora = ($data['quando'] ?? 'agora') === 'agora';

                if (Fluxo::tentar(fn () => $fluxo->iniciar($campanha, self::usuario(), $agora))) {
                    Notification::make()->success()->title($agora ? 'Campanha em andamento' : 'Campanha agendada')->send();
                }

                $campanha->refresh();
            });
    }

    public static function pausar(): Action
    {
        return Action::make('pausar')
            ->label('Pausar')
            ->icon(Heroicon::OutlinedPause)
            ->color('warning')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && in_array(self::campanha($livewire)->status, [Campaign::STATUS_EM_ANDAMENTO, Campaign::STATUS_APROVADA], true))
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);

                if (Fluxo::tentar(fn () => $fluxo->pausar($campanha, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Campanha pausada')->send();
                }

                $campanha->refresh();
            });
    }

    public static function retomar(): Action
    {
        return Action::make('retomar')
            ->label('Retomar')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->color('primary')
            ->modalDescription(fn (ViewRecord $livewire): string => 'Pausada: '.(self::campanha($livewire)->pausada_motivo ?: 'sem motivo registrado').'. Retomar é decisão de quem aprova: a pausa pode ter sido do disjuntor.')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::APPROVE_CAMPAIGNS)
                && self::campanha($livewire)->status === Campaign::STATUS_PAUSADA)
            ->schema([
                Textarea::make('motivo')->label('Por que retomar')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);

                if (Fluxo::tentar(fn () => $fluxo->retomar($campanha, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Campanha retomada')->send();
                }

                $campanha->refresh();
            });
    }

    public static function cancelar(): Action
    {
        return Action::make('cancelar')
            ->label('Cancelar campanha')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Encerra a campanha de vez: o que não saiu não sai mais. Para parar por um tempo, use "Pausar".')
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && self::campanha($livewire)->aberta())
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->minLength(3)->maxLength(500)->rows(2),
            ])
            ->action(function (array $data, ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);

                if (Fluxo::tentar(fn () => $fluxo->cancelar($campanha, self::usuario(), (string) $data['motivo']))) {
                    Notification::make()->success()->title('Campanha cancelada')->send();
                }

                $campanha->refresh();
            });
    }

    public static function duplicar(): Action
    {
        return Action::make('duplicar')
            ->label('Duplicar')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->requiresConfirmation()
            ->modalDescription('Cria um rascunho com o mesmo template, variáveis, público e regras. Passa pela prévia e pela aprovação de novo.')
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS))
            ->action(function (ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $nova = null;

                if (Fluxo::tentar(function () use ($fluxo, $campanha, &$nova): void {
                    $nova = $fluxo->duplicar($campanha, self::usuario());
                }) && $nova instanceof Campaign) {
                    Notification::make()->success()->title('Campanha duplicada')->body('Você está no rascunho novo.')->send();
                    $livewire->redirect(CampaignResource::getUrl('edit', ['record' => $nova]));
                }
            });
    }

    public static function reenviarFalhas(): Action
    {
        return Action::make('reenviar_falhas')
            ->label('Reenviar falhas')
            ->icon(Heroicon::OutlinedArrowPath)
            ->requiresConfirmation()
            ->modalDescription(fn (ViewRecord $livewire): string => sprintf('Cria uma campanha filha com os %d destinatário(s) que falharam nesta. Passa pela prévia e pela aprovação de novo.', self::campanha($livewire)->falhas))
            ->visible(fn (ViewRecord $livewire): bool => Permissions::allows(Permissions::MANAGE_CAMPAIGNS)
                && ! self::campanha($livewire)->aberta()
                && self::campanha($livewire)->falhas > 0)
            ->action(function (ViewRecord $livewire, FluxoDeCampanha $fluxo): void {
                $campanha = self::campanha($livewire);
                $nova = null;

                if (Fluxo::tentar(function () use ($fluxo, $campanha, &$nova): void {
                    $nova = $fluxo->reenviarFalhas($campanha, self::usuario());
                }) && $nova instanceof Campaign) {
                    Notification::make()->success()->title('Campanha de reenvio criada')->send();
                    $livewire->redirect(CampaignResource::getUrl('edit', ['record' => $nova]));
                }
            });
    }

    /** O que quem aprova precisa ler (KB 2.2): contagem por motivo, custo, teto restante e dias estimados. */
    public static function resumoDaPrevia(Campaign $campanha): string
    {
        $previa = (array) ($campanha->simulacao ?? []);

        if ($previa === []) {
            return 'Sem prévia: gere a prévia antes. A aprovação só vale com a prévia atual.';
        }

        $linhas = [];
        $gerada = isset($previa['gerada_em']) ? CarbonImmutable::parse((string) $previa['gerada_em'])->timezone('America/Sao_Paulo')->format('d/m H:i') : '?';
        $linhas[] = sprintf('Prévia de %s: %d elegíveis, %d excluídos de %d.', $gerada, (int) ($previa['elegiveis'] ?? 0), (int) ($previa['excluidos'] ?? 0), (int) ($previa['total'] ?? 0));

        if (! $campanha->previaAtual()) {
            $linhas[] = 'ATENÇÃO: a campanha foi editada depois desta prévia. Gere outra.';
        }

        $motivos = collect((array) ($previa['motivos'] ?? []))
            ->map(fn ($n, $motivo): string => (CampaignRecipient::motivoLabels()[$motivo] ?? $motivo).': '.$n)
            ->implode(' · ');

        if ($motivos !== '') {
            $linhas[] = 'Excluídos por: '.$motivos.'.';
        }

        $linhas[] = self::linhaDeCusto($previa);

        foreach ((array) ($previa['avisos'] ?? []) as $aviso) {
            $linhas[] = 'Aviso: '.$aviso;
        }

        return implode(' ', $linhas);
    }

    /** @param  array<string, mixed>  $previa */
    public static function linhaDeCusto(array $previa): string
    {
        return sprintf(
            'Custo estimado R$ %s · teto restante hoje no remetente: %d · sai em ~%d dia(s) no ritmo atual.',
            number_format((float) ($previa['custo_estimado'] ?? 0), 2, ',', '.'),
            (int) ($previa['teto_restante_hoje'] ?? 0),
            (int) ($previa['dias_estimados'] ?? 0),
        );
    }

    /**
     * CSV dos destinatários em storage (telefone mascarado sem `view pii`; variáveis só com), e devolve o
     * caminho relativo ao disco `local`. Célula que começa com = + - @ ganha apóstrofo: fórmula não roda.
     */
    public static function exportarCsv(Campaign $campanha, bool $pii): string
    {
        $caminho = sprintf('exportacoes/campanha-%d-%s.csv', $campanha->id, now()->format('Ymd-His'));
        $arquivo = fopen('php://temp', 'w+');
        fwrite($arquivo, "\xEF\xBB\xBF");

        $cabecalho = ['telefone', 'contato', 'status', 'motivo', 'wamid', 'codigo_erro', 'enviado_em', 'entregue_em', 'lido_em', 'respondido_em'];

        if ($pii) {
            $cabecalho[] = 'variaveis';
        }

        fputcsv($arquivo, $cabecalho, ';');

        $campanha->recipients()->with('contact')->orderBy('id')->chunkById(500, function ($destinatarios) use ($arquivo, $pii): void {
            foreach ($destinatarios as $destinatario) {
                $linha = [
                    $pii ? Telefone::formatar($destinatario->telefone) : Telefone::mascarar($destinatario->telefone),
                    (string) ($destinatario->contact?->nome ?? ''),
                    CampaignRecipient::statusLabels()[$destinatario->status] ?? $destinatario->status,
                    CampaignRecipient::motivoLabels()[$destinatario->motivo] ?? (string) $destinatario->motivo,
                    (string) $destinatario->wamid,
                    (string) $destinatario->codigo_erro,
                    $destinatario->enviado_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '',
                    $destinatario->entregue_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '',
                    $destinatario->lido_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '',
                    $destinatario->respondido_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i') ?? '',
                ];

                if ($pii) {
                    $linha[] = collect((array) ($destinatario->variaveis ?? []))->map(fn ($v, $k): string => $k.'='.$v)->implode(' | ');
                }

                fputcsv($arquivo, array_map([self::class, 'celulaSegura'], $linha), ';');
            }
        });

        rewind($arquivo);
        Storage::disk('local')->put($caminho, stream_get_contents($arquivo) ?: '');
        fclose($arquivo);

        return $caminho;
    }

    public static function celulaSegura(string $valor): string
    {
        return preg_match('/^[=+\-@]/', $valor) === 1 ? "'".$valor : $valor;
    }

    private static function campanha(ViewRecord $livewire): Campaign
    {
        /** @var Campaign $campanha */
        $campanha = $livewire->getRecord();

        return $campanha;
    }

    private static function usuario(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
