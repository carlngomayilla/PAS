<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;

class ArraySheetExport implements FromArray, ShouldAutoSize, WithTitle
{
    /**
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        private readonly string $title,
        private readonly array $rows
    ) {}

    /**
     * @return list<list<mixed>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }
}
