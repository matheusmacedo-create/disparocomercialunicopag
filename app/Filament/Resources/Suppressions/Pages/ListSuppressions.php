<?php

namespace App\Filament\Resources\Suppressions\Pages;

use App\Filament\Resources\Suppressions\SuppressionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSuppressions extends ListRecords
{
    protected static string $resource = SuppressionResource::class;

    public function getSubheading(): ?string
    {
        return 'Pedido para parar vale para o número, em todos os remetentes, para sempre (KB 2.3). Liberar é exceção, com justificativa.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Bloquear telefone'),
        ];
    }
}
