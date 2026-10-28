<?php

namespace App\Filament\Resources\Contacts\Support;

use App\Models\Contact;
use App\Models\ContactEvent;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\Sender;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\WhatsappMessage;
use App\Services\Contatos\AnonimizarContato;
use App\Services\Contatos\ExportarDadosDoTitular;
use App\Services\Envio\Despachante;
use App\Services\Reguas\FluxoDeRegua;
use App\Support\Mask;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ações sobre o contato (ficha e lista). A regra mora nos models e nos serviços; aqui só a tela e o
 * gate de permissão - que o Filament reconfere no servidor antes de executar ação escondida.
 *
 * Serviços de outra frente (assinaturas combinadas no brief, resolvidos pelo container para aceitar
 * método estático ou de instância):
 *   App\Services\Contatos\AnonimizarContato::executar(Contact $contato, User $user, string $motivo)
 *   App\Services\Contatos\ExportarDadosDoTitular::executar(Contact $contato): array
 */
final class ContactActions
{
    private const FUSO = 'America/Sao_Paulo';

    /** Bloqueio manual: vale para o número, em todos os remetentes, para sempre (KB 2.3). */
    public static function bloquear(): Action
    {
        return Action::make('bloquear')
            ->label('Bloquear')
            ->icon(Heroicon::NoSymbol)
            ->color('danger')
            ->modalHeading('Bloquear este telefone para sempre')
            ->modalDescription('Vale para todos os remetentes e qualquer template. Só quem tem "liberar bloqueios" desfaz, com justificativa.')
            ->modalSubmitActionLabel('Bloquear')
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->maxLength(500)->rows(3),
            ])
            ->visible(fn (Contact $record): bool => Permissions::allows(Permissions::MANAGE_CONTACTS) && ! $record->anonimizado())
            ->action(function (array $data, Contact $record): void {
                $criou = Suppression::suprimir(
                    $record->telefone,
                    Suppression::MOTIVO_MANUAL,
                    'painel',
                    null,
                    Suppression::ESCOPO_TUDO,
                    null,
                    (string) $data['motivo'],
                    auth()->id(),
                );

                $record->refresh();

                $criou
                    ? Notification::make()->success()->title('Telefone bloqueado')->body('Nenhum remetente manda mais nada para ele.')->send()
                    : Notification::make()->warning()->title('Telefone já estava bloqueado')->send();
            });
    }

    /** Direito de exclusão: irreversível, confirmação digitada, só `anonymize contacts`. */
    public static function anonimizar(): Action
    {
        return Action::make('anonimizar')
            ->label('Anonimizar')
            ->icon(Heroicon::ShieldExclamation)
            ->color('danger')
            ->modalHeading('Anonimizar o contato (direito de exclusão)')
            ->modalDescription('Irreversível: apaga nome, e-mail, CPF/CNPJ, atributos e o conteúdo das mensagens; o telefone vira um identificador anônimo. O bloqueio pelo hash do telefone permanece, para ele nunca mais receber nada.')
            ->modalSubmitActionLabel('Anonimizar')
            ->schema([
                Textarea::make('motivo')->label('Motivo / protocolo do pedido')->required()->maxLength(500)->rows(2),
                TextInput::make('confirmacao')
                    ->label('Digite ANONIMIZAR para confirmar')
                    ->required()
                    ->in(['ANONIMIZAR'])
                    ->validationMessages(['in' => 'Digite exatamente ANONIMIZAR.']),
            ])
            ->visible(fn (Contact $record): bool => Permissions::allows(Permissions::ANONYMIZE_CONTACTS) && ! $record->anonimizado())
            ->action(function (array $data, Contact $record): void {
                app(AnonimizarContato::class)->executar($record, auth()->user(), (string) $data['motivo']);

                $record->refresh();

                Notification::make()->success()->title('Contato anonimizado')->body('A trilha e o bloqueio pelo hash do telefone foram mantidos.')->send();
            });
    }

    /** Direito de acesso (Art. 18): JSON com tudo o que a plataforma guarda do titular. Só `view pii`. */
    public static function exportarDados(): Action
    {
        return Action::make('exportarDados')
            ->label('Exportar dados do titular')
            ->icon(Heroicon::ArrowDownTray)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Exportar os dados do titular (LGPD, Art. 18)')
            ->modalDescription('Gera um JSON com tudo o que a plataforma guarda deste telefone. A exportação entra na trilha do contato.')
            ->modalSubmitActionLabel('Exportar')
            ->visible(fn (Contact $record): bool => Permissions::allows(Permissions::VIEW_PII) && ! $record->anonimizado())
            ->action(function (Contact $record): StreamedResponse {
                $dados = (array) app(ExportarDadosDoTitular::class)->executar($record);

                ContactEvent::registrar($record->telefone_hash, 'dados_exportados', ['campos' => count($dados)], auth()->id(), $record->id);

                $json = (string) json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return response()->streamDownload(function () use ($json): void {
                    echo $json;
                }, 'titular-'.$record->id.'.json', ['Content-Type' => 'application/json']);
            });
    }

    /** Opt-in registrado à mão (formulário, contrato, "quero receber" fora do WhatsApp). */
    public static function registrarConsentimento(): Action
    {
        return Action::make('registrarConsentimento')
            ->label('Registrar consentimento')
            ->icon(Heroicon::ClipboardDocumentList)
            ->color('gray')
            ->modalHeading('Registrar consentimento')
            ->modalDescription('Como, quando e por qual canal o titular aceitou receber mensagens da ÚnicoPag (a Meta exige que a marca seja nomeada).')
            ->schema([
                Select::make('origem')->label('Como consentiu')->options(Contact::consentimentoLabels())->required()->native(false),
                TextInput::make('canal')->label('Canal')->required()->maxLength(30)->default('whatsapp'),
                DateTimePicker::make('quando')->label('Quando')->native(false)->seconds(false)->displayFormat('d/m/Y H:i')->default(now())->required(),
                Textarea::make('detalhes')->label('Prova / detalhes')->rows(2)->maxLength(500),
            ])
            ->visible(fn (Contact $record): bool => Permissions::allows(Permissions::MANAGE_CONTACTS) && ! $record->anonimizado())
            ->action(function (array $data, Contact $record): void {
                $quando = filled($data['quando'] ?? null) ? Carbon::parse((string) $data['quando']) : null;

                $record->registrarConsentimento((string) $data['origem'], (string) $data['canal'], $data['detalhes'] ?? null, $quando);

                ContactEvent::registrar($record->telefone_hash, 'consentimento', [
                    'origem' => $data['origem'],
                    'canal' => $data['canal'],
                    'quando' => $quando?->toIso8601String(),
                ], auth()->id(), $record->id);

                Notification::make()->success()->title('Consentimento registrado')->send();
            });
    }

    /**
     * Texto livre só dentro da janela de 24 h aberta pela última resposta do contato (a Meta recusa com
     * 131047 fora dela). Serve à ficha (registro = contato) e à tela Conversas (registro = mensagem).
     */
    public static function responder(): Action
    {
        return Action::make('responder')
            ->label('Responder')
            ->icon(Heroicon::ChatBubbleLeftRight)
            ->color('primary')
            ->modalHeading('Responder pelo WhatsApp')
            ->modalDescription(function (Model $record): string {
                $contato = self::contatoDe($record);
                $ate = WhatsappMessage::janelaAbertaAte($contato?->ultima_resposta_em);

                return $ate
                    ? 'Janela de 24 h aberta até '.$ate->timezone(self::FUSO)->format('d/m H:i').'. Texto livre, com o seu nome na trilha.'
                    : 'Janela de 24 h fechada: só template.';
            })
            ->modalSubmitActionLabel('Enviar')
            ->schema([
                Select::make('sender_id')
                    ->label('Remetente')
                    ->options(fn (): array => Sender::query()->where('ativo', true)->orderBy('nome')->pluck('nome', 'id')->all())
                    ->default(fn (Model $record): ?int => self::remetenteDaUltimaResposta(self::contatoDe($record), $record))
                    ->required()
                    ->native(false),
                Textarea::make('texto')->label('Mensagem')->required()->rows(4)->maxLength(4000),
            ])
            ->visible(function (Model $record): bool {
                $contato = self::contatoDe($record);

                return Permissions::allows(Permissions::VIEW_CONVERSATIONS)
                    && $contato !== null
                    && ! $contato->anonimizado()
                    && WhatsappMessage::janelaAbertaAte($contato->ultima_resposta_em) !== null;
            })
            ->action(function (array $data, Model $record, Despachante $despachante): void {
                $contato = self::contatoDe($record);
                $sender = Sender::query()->findOrFail((int) $data['sender_id']);

                if ($contato === null || WhatsappMessage::janelaAbertaAte($contato->ultima_resposta_em) === null) {
                    Notification::make()->danger()->title('Janela de 24 h fechada')->body('A Meta só aceita texto livre até 24 h depois da última mensagem do cliente. Use um template.')->send();

                    return;
                }

                [$mensagem, $excecao] = $despachante->enviarTexto($sender, $contato, (string) $data['texto'], WhatsappMessage::ORIGEM_MANUAL, [], auth()->id());

                $excecao === null
                    ? Notification::make()->success()->title('Resposta enviada')->send()
                    : Notification::make()->danger()->title('Resposta não enviada')->body(Mask::scrub($excecao->getMessage()))->persistent()->send();
            });
    }

    public static function bulkAdicionarBase(): BulkAction
    {
        return BulkAction::make('adicionar_base')
            ->label('Adicionar a uma base')
            ->icon(Heroicon::RectangleStack)
            ->schema([
                Select::make('contact_list_id')
                    ->label('Base')
                    ->options(fn (): array => ContactList::query()->where('ativa', true)->orderBy('nome')->pluck('nome', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_CONTACTS))
            ->action(function (array $data, Collection $records): void {
                $lista = ContactList::query()->findOrFail((int) $data['contact_list_id']);

                $lista->contacts()->syncWithoutDetaching(
                    $records->mapWithKeys(fn (Contact $contato): array => [$contato->id => ['adicionado_em' => now()]])->all(),
                );

                Notification::make()->success()->title($records->count().' contato(s) na base "'.$lista->nome.'"')->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkAdicionarTag(): BulkAction
    {
        return BulkAction::make('adicionar_tag')
            ->label('Adicionar tag')
            ->icon(Heroicon::Tag)
            ->schema([
                Select::make('tag_id')
                    ->label('Tag')
                    ->options(fn (): array => Tag::query()->orderBy('nome')->pluck('nome', 'id')->all())
                    ->required()
                    ->searchable()
                    ->native(false),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_CONTACTS))
            ->action(function (array $data, Collection $records): void {
                $tag = Tag::query()->findOrFail((int) $data['tag_id']);

                $records->each(fn (Contact $contato) => $contato->tags()->syncWithoutDetaching([$tag->id => ['created_at' => now()]]));

                Notification::make()->success()->title('Tag "'.$tag->nome.'" em '.$records->count().' contato(s)')->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkBloquear(): BulkAction
    {
        return BulkAction::make('bloquear')
            ->label('Bloquear')
            ->icon(Heroicon::NoSymbol)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Bloquear os telefones selecionados para sempre')
            ->modalDescription('Vale para todos os remetentes e qualquer template; só quem tem "liberar bloqueios" desfaz, um a um, com justificativa.')
            ->schema([
                Textarea::make('motivo')->label('Motivo')->required()->maxLength(500)->rows(2),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_CONTACTS))
            ->action(function (array $data, Collection $records): void {
                $bloqueados = 0;

                foreach ($records as $contato) {
                    if ($contato->anonimizado()) {
                        continue;
                    }

                    if (Suppression::suprimir($contato->telefone, Suppression::MOTIVO_MANUAL, 'painel', null, Suppression::ESCOPO_TUDO, null, (string) $data['motivo'], auth()->id())) {
                        $bloqueados++;
                    }
                }

                Notification::make()->success()->title($bloqueados.' telefone(s) bloqueado(s)')->body($records->count() - $bloqueados > 0 ? ($records->count() - $bloqueados).' já estavam bloqueados ou anonimizados.' : null)->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkInscreverRegua(): BulkAction
    {
        return BulkAction::make('inscrever_regua')
            ->label('Inscrever em régua')
            ->icon(Heroicon::QueueList)
            ->schema([
                Select::make('journey_id')
                    ->label('Régua ativa')
                    ->options(fn (): array => Journey::query()->where('status', Journey::STATUS_ATIVA)->orderBy('nome')->pluck('nome', 'id')->all())
                    ->required()
                    ->native(false)
                    ->helperText('Só régua aprovada e ativa recebe inscrições; quem já está nela, ou saiu há pouco, não entra de novo.'),
            ])
            ->visible(fn (): bool => Permissions::allows(Permissions::MANAGE_JOURNEYS))
            ->action(function (array $data, Collection $records, FluxoDeRegua $fluxo): void {
                $journey = Journey::query()->findOrFail((int) $data['journey_id']);
                $inscritos = 0;

                try {
                    foreach ($records as $contato) {
                        if ($contato->ativo() && $fluxo->inscrever($journey, $contato, auth()->user())) {
                            $inscritos++;
                        }
                    }
                } catch (ValidationException $e) {
                    Notification::make()->danger()->title('Inscrição não feita')->body(implode(' ', array_map('implode', $e->errors())))->send();

                    return;
                }

                Notification::make()->success()->title($inscritos.' contato(s) inscrito(s) em "'.$journey->nome.'"')->body($records->count() - $inscritos > 0 ? ($records->count() - $inscritos).' ficaram de fora (já inscritos, inativos ou fora da janela de reinscrição).' : null)->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /** O contato por trás do registro da página: o próprio (ficha) ou o contato da mensagem (Conversas). */
    public static function contatoDe(?Model $record): ?Contact
    {
        if ($record instanceof Contact) {
            return $record;
        }

        if ($record instanceof WhatsappMessage) {
            return $record->contact;
        }

        return null;
    }

    /** Remetente que recebeu a última resposta do contato (é por ele que a janela de 24 h está aberta). */
    private static function remetenteDaUltimaResposta(?Contact $contato, Model $record): ?int
    {
        if ($record instanceof WhatsappMessage) {
            return $record->sender_id;
        }

        if ($contato === null) {
            return null;
        }

        $ultima = WhatsappMessage::query()
            ->inbound()
            ->where('telefone', $contato->telefone)
            ->latest('enviado_em')
            ->first(['sender_id']);

        return $ultima?->sender_id;
    }
}
