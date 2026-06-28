<?php

namespace App\Exports;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class PtaImportErrorsExport implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly AiImportBatch $batch
    ) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        $rows = [[
            'Ligne',
            'Statut',
            'Erreurs',
            'Avertissements',
        ]];

        foreach ($this->batch->rows()->where('status', AiImportRow::STATUS_INVALID)->get() as $row) {
            $rows[] = [
                $row->row_number,
                $row->status,
                implode(' | ', $row->validation_errors['errors'] ?? []),
                implode(' | ', $row->validation_errors['warnings'] ?? []),
            ];
        }

        return $rows;
    }

    public function title(): string
    {
        return 'Erreurs';
    }
}
