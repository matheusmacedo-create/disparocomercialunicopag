<?php

namespace App\Filament\Resources\Segments\Pages;

use App\Filament\Resources\Segments\SegmentResource;
use App\Models\Segment;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditSegment extends EditRecord
{
    protected static string $resource = SegmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('ver_contatos')
                ->label('Ver contatos')
                ->icon('heroicon-o-users')
                ->color('gray')
                ->url(fn (Segment $record): string => SegmentResource::urlDosContatos($record)),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['regras'] = SegmentResource::limparRegras((array) ($data['regras'] ?? []));

        return $data;
    }
}
