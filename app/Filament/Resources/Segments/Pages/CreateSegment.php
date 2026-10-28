<?php

namespace App\Filament\Resources\Segments\Pages;

use App\Filament\Resources\Segments\SegmentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSegment extends CreateRecord
{
    protected static string $resource = SegmentResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['regras'] = SegmentResource::limparRegras((array) ($data['regras'] ?? []));

        return $data;
    }
}
