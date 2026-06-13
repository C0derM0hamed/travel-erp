<?php

namespace App\Services\Exports;

use Illuminate\Support\Facades\View;
use Mpdf\Mpdf;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class PdfExportService
{
    public function __construct(private ExportContext $context) {}

    /** @param array<string, mixed> $data */
    public function download(string $view, array $data, string $filename, bool $inline = false): BaseResponse
    {
        $payload = array_merge($data, [
            'branding' => $this->context->branding(),
            'generatedAt' => now()->timezone('Asia/Kuwait')->format('Y-m-d H:i'),
        ]);

        $landscape = (bool) ($data['landscape'] ?? false);
        $html = View::make($view, $payload)->render();
        $pdf = $this->render($html, $landscape);
        $name = $this->context->filename($filename, 'pdf');

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /** @param list<string> $headers @param list<list<string|float|int|null>> $rows */
    public function table(string $title, array $headers, array $rows, string $filename, bool $inline = false, ?string $subtitle = null): BaseResponse
    {
        return $this->download('exports.table', [
            'title' => $title,
            'subtitle' => $subtitle,
            'headers' => $headers,
            'rows' => $rows,
            'landscape' => count($headers) > 6,
        ], $filename, $inline);
    }

    private function render(string $html, bool $landscape): string
    {
        $tempDir = storage_path('app/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => $landscape ? 'A4-L' : 'A4',
            'default_font' => 'xbriyaz',
            'tempDir' => $tempDir,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 12,
            'margin_bottom' => 12,
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->autoScriptToLang = true;
        $mpdf->autoLangToFont = true;
        $mpdf->WriteHTML($html);

        return $mpdf->Output('', 'S');
    }
}
