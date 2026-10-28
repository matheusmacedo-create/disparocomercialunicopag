<?php

namespace App\Filament\Resources\ContactImports\Pages;

use App\Filament\Resources\ContactImports\ContactImportResource;
use App\Models\ContactImport;
use App\Support\Permissions;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewContactImport extends ViewRecord
{
    protected static string $resource = ContactImportResource::class;

    public function getTitle(): string
    {
        $import = $this->getRecord();

        return 'Importação #'.$import->id.($import->nome_original ? ' - '.$import->nome_original : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('baixarRejeitadas')
                ->label('Baixar rejeitadas')
                ->icon(Heroicon::ArrowDownTray)
                ->color('gray')
                // Todas as linhas rejeitadas com o motivo (KB 4.4). Têm telefone em claro: é exportação,
                // logo `view pii` além de `import contacts`.
                ->visible(fn (ContactImport $record): bool => Permissions::allows(Permissions::IMPORT_CONTACTS)
                    && Permissions::allows(Permissions::VIEW_PII)
                    && Storage::disk('local')->exists(ContactImportResource::caminhoDasRejeitadas($record)))
                ->action(fn (ContactImport $record): StreamedResponse => Storage::disk('local')->download(
                    ContactImportResource::caminhoDasRejeitadas($record),
                    'rejeitadas-importacao-'.$record->id.'.csv',
                )),
        ];
    }
}
