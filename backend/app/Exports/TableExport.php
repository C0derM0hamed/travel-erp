<?php

namespace App\Exports;

use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

class TableExport implements FromGenerator, ShouldAutoSize, WithEvents, WithHeadings
{
    /** @param list<string> $headings */
    public function __construct(
        private array $headings,
        private iterable $rows,
    ) {}

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function generator(): Generator
    {
        foreach ($this->rows as $row) {
            yield array_map(fn ($cell) => $this->normalizeCell($cell), $row);
        }
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->setRightToLeft(true);

                $highestColumn = $sheet->getHighestColumn();
                $highestRow = max(1, $sheet->getHighestRow());
                $range = "A1:{$highestColumn}{$highestRow}";

                $sheet->getStyle($range)->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_RIGHT)
                    ->setVertical(Alignment::VERTICAL_CENTER)
                    ->setWrapText(true);

                $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);
            },
        ];
    }

    private function normalizeCell(mixed $cell): mixed
    {
        if (is_string($cell)) {
            return mb_convert_encoding($cell, 'UTF-8', 'UTF-8');
        }

        return $cell;
    }
}
