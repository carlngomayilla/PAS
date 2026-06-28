<?php

namespace App\Exports;

use App\Models\AiImportBatch;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class PtaImportMetadataExport implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly AiImportBatch $batch
    ) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return [
            ['Cle', 'Valeur'],
            ['Import', $this->batch->id],
            ['Fichier original', $this->batch->original_filename],
            ['Statut', $this->batch->status],
            ['Score confiance', $this->batch->confidence_score],
            ['Exercice detecte', $this->batch->detected_year],
            ['Direction detectee', $this->batch->detected_direction],
            ['Service detecte', $this->batch->detected_service],
            ['Lignes', $this->batch->rows()->count()],
            ['Lignes invalides', $this->batch->blockingRows()->count()],
            ['Genere le', now()->toDateTimeString()],
        ];
    }

    public function title(): string
    {
        return 'Metadonnees';
    }
}
