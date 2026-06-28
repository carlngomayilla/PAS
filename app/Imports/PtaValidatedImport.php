<?php

namespace App\Imports;

use App\Models\AiImportBatch;
use App\Models\AiImportRow;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class PtaValidatedImport implements ToCollection
{
    public function __construct(
        private readonly AiImportBatch $batch
    ) {}

    public function collection(Collection $rows): void
    {
        $headers = [];

        foreach ($rows as $index => $row) {
            $values = $row instanceof Collection ? $row->values()->all() : (array) $row;
            if ($index === 0) {
                $headers = array_map(static fn (mixed $value): string => strtolower(trim((string) $value)), $values);

                continue;
            }

            if (collect($values)->every(static fn (mixed $value): bool => trim((string) $value) === '')) {
                continue;
            }

            AiImportRow::query()->create([
                'batch_id' => $this->batch->id,
                'row_number' => $index + 1,
                'raw_payload' => array_combine($headers, $values) ?: [],
                'status' => AiImportRow::STATUS_PENDING,
            ]);
        }
    }
}
