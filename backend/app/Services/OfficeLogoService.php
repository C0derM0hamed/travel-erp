<?php

namespace App\Services;

use App\Models\Office;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OfficeLogoService
{
    public const DISK = 'public';

    public const DIRECTORY = 'office-logos';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public const MAX_KILOBYTES = 5120;

    public function upload(Office $office, UploadedFile $file): string
    {
        $this->assertValid($file);
        $this->deleteStoredFile($office->logo);

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $filename = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs(self::DIRECTORY.'/'.$office->id, $filename, self::DISK);
    }

    public function deleteStoredFile(?string $path): void
    {
        if (! $path || ! $this->isManagedPath($path)) {
            return;
        }

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        if (! $path || ! $this->isManagedPath($path)) {
            return null;
        }

        if (! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->url($path);
    }

    public function isManagedPath(string $path): bool
    {
        return str_starts_with($path, self::DIRECTORY.'/');
    }

    public function assertValid(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'logo' => 'نوع الملف غير مدعوم. المسموح: JPG, JPEG, PNG, WEBP',
            ]);
        }

        if ($file->getSize() > self::MAX_KILOBYTES * 1024) {
            throw ValidationException::withMessages([
                'logo' => 'حجم الشعار يجب ألا يتجاوز 5 ميجابايت',
            ]);
        }
    }

    /** @return list<string> */
    public static function validationRules(bool $required = false): array
    {
        $rules = ['file', 'mimes:jpeg,jpg,png,webp', 'max:'.self::MAX_KILOBYTES];

        return [$required ? 'required' : 'nullable', ...$rules];
    }
}
