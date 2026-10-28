<?php

namespace App\Filament\Resources\Journeys\Schemas;

use App\Filament\Resources\Journeys\Support\JourneyActions;
use App\Models\Campaign;
use App\Models\ContactList;
use App\Models\Journey;
use App\Models\JourneyEnrollment;
use App\Models\JourneyEvent;
use App\Models\JourneyStep;
use App\Models\Segment;
use App\Models\Tag;
use App\Models\User;
use App\Support\Mask;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class JourneyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Grid::make(1)
                    ->columnSpan(2)
                    ->schema([
                        Section::make('Régua')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state): string => Journey::statusLabels()[$state] ?? (string) $state)
                                    ->color(fn (?string $state): string => JourneyActions::statusColor($state)),
                                TextEntry::make('sender.nome')->label('Remetente'),
                                TextEntry::make('objetivo')->label('Objetivo')->formatStateUsing(fn (?string $state): string => Campaign::objetivoLabels()[$state] ?? (string) $state),
                                TextEntry::make('gatilho')
                                    ->label('Gatilho')
                                    ->formatStateUsing(fn (?string $state): string => Journey::gatilhoLabels()[$state] ?? (string) $state)
                                    ->helperText(fn (Journey $record): ?string => self::referencia($record)),
                                TextEntry::make('regras')
                                    ->label('Regras')
                                    ->state(fn (Journey $record): string => self::regras($record))
                                    ->columnSpan(2),
                                TextEntry::make('criado_por')
                                    ->label('Criada por')
                                    ->state(fn (Journey $record): ?string => $record->criado_por ? User::query()->find($record->criado_por)?->name : null)
                                    ->placeholder('—'),
                                TextEntry::make('aprovado_por')
                                    ->label('Aprovada por')
                                    ->state(fn (Journey $record): ?string => $record->aprovado_por ? User::query()->find($record->aprovado_por)?->name : null)
                                    ->helperText(fn (Journey $record): ?string => $record->aprovado_em?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'))
                                    ->placeholder('—'),
                                TextEntry::make('inscricoes')
                                    ->label('Inscrições')
                                    ->state(fn (Journey $record): string => sprintf(
                                        '%d ativas · %d concluídas · %d saíram',
                                        $record->enrollments()->where('status', JourneyEnrollment::STATUS_ATIVA)->count(),
                                        $record->enrollments()->where('status', JourneyEnrollment::STATUS_CONCLUIDA)->count(),
                                        $record->enrollments()->where('status', JourneyEnrollment::STATUS_SAIU)->count(),
                                    )),
                                TextEntry::make('descricao')->label('Descrição')->placeholder('—')->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-line']),
                            ]),

                        Section::make('Passos')
                            ->schema([
                                RepeatableEntry::make('steps')
                                    ->hiddenLabel()
                                    ->placeholder('Sem passos: edite a régua.')
                                    ->columns(4)
                                    ->schema([
                                        TextEntry::make('ordem')->label('#')->badge()->color('gray'),
                                        TextEntry::make('tipo')->label('Tipo')->formatStateUsing(fn (?string $state): string => JourneyStep::tipoLabels()[$state] ?? (string) $state),
                                        TextEntry::make('detalhe')
                                            ->label('Detalhe')
                                            ->state(fn (JourneyStep $record): string => self::detalheDoPasso($record))
                                            ->columnSpan(2)
                                            ->wrap(),
                                    ]),
                            ]),
                    ]),

                Grid::make(1)
                    ->columnSpan(1)
                    ->schema([
                        Section::make('Trilha')
                            ->schema([
                                RepeatableEntry::make('trilha')
                                    ->hiddenLabel()
                                    ->state(fn (Journey $record) => $record->events()->limit(40)->get())
                                    ->placeholder('Nada registrado ainda.')
                                    ->schema([
                                        TextEntry::make('evento')
                                            ->hiddenLabel()
                                            ->badge()
                                            ->formatStateUsing(fn (string $state): string => self::eventoLabel($state))
                                            ->color(fn (string $state): string => match ($state) {
                                                'aprovada', 'ativada' => 'success',
                                                'pausada', 'reprovada', 'editada' => 'warning',
                                                default => 'gray',
                                            }),
                                        TextEntry::make('ator')
                                            ->hiddenLabel()
                                            ->state(fn (JourneyEvent $record): string => (string) ($record->ator ?: 'sistema'))
                                            ->helperText(fn (JourneyEvent $record): string => $record->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') ?? ''),
                                        TextEntry::make('detalhes')
                                            ->hiddenLabel()
                                            ->state(fn (JourneyEvent $record): ?string => self::detalhes((array) ($record->detalhes ?? [])))
                                            ->color('gray')
                                            ->visible(fn (JourneyEvent $record): bool => filled($record->detalhes)),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function eventoLabel(string $evento): string
    {
        return [
            'criada' => 'Criada',
            'editada' => 'Editada (voltou a rascunho)',
            'enviada_para_aprovacao' => 'Enviada para aprovação',
            'aprovada' => 'Aprovada',
            'reprovada' => 'Devolvida para ajuste',
            'ativada' => 'Ativada',
            'pausada' => 'Pausada',
            'inscricao_manual' => 'Inscrição manual',
        ][$evento] ?? $evento;
    }

    private static function referencia(Journey $record): ?string
    {
        if ($record->gatilho_ref === null) {
            return null;
        }

        $nome = match ($record->gatilho) {
            Journey::GATILHO_LISTA => ContactList::query()->find($record->gatilho_ref)?->nome,
            Journey::GATILHO_TAG => Tag::query()->find($record->gatilho_ref)?->nome,
            Journey::GATILHO_SEGMENTO => Segment::query()->find($record->gatilho_ref)?->nome,
            default => null,
        };

        return $nome ?? '#'.$record->gatilho_ref;
    }

    private static function regras(Journey $record): string
    {
        $partes = [
            'até '.(int) $record->max_por_dia.' por dia',
            'intervalo mínimo '.(int) $record->intervalo_minimo_horas.' h',
            $record->sair_ao_responder ? 'sai ao responder' : 'não sai ao responder',
            $record->sair_ao_converter ? 'sai ao converter' : 'não sai ao converter',
            $record->ignora_conversa_aberta ? 'ignora conversa aberta' : 'respeita conversa aberta',
            $record->reinscrever_apos_dias ? 'reinscreve depois de '.$record->reinscrever_apos_dias.' dias' : 'nunca reinscreve',
        ];

        return ucfirst(implode(' · ', $partes)).'.';
    }

    private static function detalheDoPasso(JourneyStep $passo): string
    {
        $variaveis = (array) ($passo->variaveis ?? []);

        return match ($passo->tipo) {
            JourneyStep::TIPO_TEMPLATE => $passo->template_nome.' ('.$passo->template_idioma.')'.(isset($variaveis['corpo']) ? ' · '.implode(' | ', array_map('strval', (array) $variaveis['corpo'])) : ''),
            JourneyStep::TIPO_AGUARDAR => $passo->aguardar_horas.' h',
            JourneyStep::TIPO_CONDICAO => JourneyStep::condicaoLabels()[$passo->condicao] ?? (string) $passo->condicao,
            JourneyStep::TIPO_TAG => $passo->tag?->nome ?? '#'.$passo->tag_id,
            JourneyStep::TIPO_TEXTO => mb_substr((string) ($variaveis['texto'] ?? ''), 0, 160),
            default => '',
        };
    }

    /** @param  array<string, mixed>  $detalhes */
    private static function detalhes(array $detalhes): ?string
    {
        $texto = collect($detalhes)
            ->map(fn ($valor, $chave): string => $chave.': '.(is_scalar($valor) || $valor === null ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE)))
            ->implode(' · ');

        return $texto === '' ? null : mb_substr(Mask::scrub($texto), 0, 400);
    }
}
