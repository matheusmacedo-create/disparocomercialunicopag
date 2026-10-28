<?php

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\MessageTemplates\Tables\MessageTemplatesTable;
use App\Models\MessageTemplate;
use Filament\Resources\Pages\ViewRecord;

class ViewMessageTemplate extends ViewRecord
{
    protected static string $resource = MessageTemplateResource::class;

    public function getTitle(): string
    {
        /** @var MessageTemplate $template */
        $template = $this->getRecord();

        return $template->nome.' ('.$template->idioma.')';
    }

    protected function getHeaderActions(): array
    {
        return [
            MessageTemplatesTable::sincronizar(),
        ];
    }
}
