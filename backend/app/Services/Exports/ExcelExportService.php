<?php

namespace App\Services\Exports;

use App\Exports\TableExport;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExcelExportService
{
    public function __construct(private ExportContext $context) {}

    /** @param list<string> $headings @param iterable<int, array<int, string|float|int|null>> $rows */
    public function download(array $headings, iterable $rows, string $filename): BinaryFileResponse
    {
        return Excel::download(
            new TableExport($headings, $rows),
            $this->context->filename($filename, 'xlsx'),
        );
    }
}
