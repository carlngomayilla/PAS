<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class ArraySheetExport extends DefaultValueBinder implements FromArray, WithColumnWidths, WithCustomValueBinder, WithTitle
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

    /**
     * @return array<string, int>
     */
    public function columnWidths(): array
    {
        $maxColumns = 1;

        foreach ($this->rows as $row) {
            if (is_array($row)) {
                $maxColumns = max($maxColumns, count($row));
            }
        }

        $widths = [];

        for ($column = 1; $column <= $maxColumns; $column++) {
            $widths[Coordinate::stringFromColumnIndex($column)] = $column <= 3 ? 18 : 32;
        }

        return $widths;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
