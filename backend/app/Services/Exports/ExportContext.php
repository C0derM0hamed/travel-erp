<?php

namespace App\Services\Exports;

use App\Models\Office;
use App\Services\OfficeLogoService;
use App\Support\OfficeContext;
use Illuminate\Support\Str;

class ExportContext
{
    public function __construct(
        private OfficeContext $officeContext,
        private OfficeLogoService $logoService,
    ) {}

    public function office(): Office
    {
        $office = $this->officeContext->office();
        if (! $office) {
            $this->officeContext->requireId();
            $office = $this->officeContext->office();
        }

        return $office;
    }

    /** @return array{id:int,office_code:string,office_name:string,logo_url:?string} */
    public function branding(): array
    {
        $office = $this->office();

        return [
            'id' => $office->id,
            'office_code' => $office->office_code,
            'office_name' => $office->office_name,
            'logo_url' => $this->logoAbsoluteUrl($office),
        ];
    }

    public function logoAbsoluteUrl(Office $office): ?string
    {
        $relative = $this->logoService->url($office->logo);
        if (! $relative) {
            return null;
        }

        if (str_starts_with($relative, 'http')) {
            return $relative;
        }

        return url($relative);
    }

    public function filename(string $base, string $extension): string
    {
        $safe = Str::slug(Str::ascii($base), '_');
        if ($safe === '') {
            $safe = 'export';
        }

        return $safe.'.'.$extension;
    }
}
