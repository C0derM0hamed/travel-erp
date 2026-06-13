<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Support\OfficeContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;

class ActivityLogger
{
    public function log(string $action, ?Model $subject = null, array $payload = [], ?int $userId = null): void
    {
        $officeId = null;
        if ($subject && isset($subject->office_id)) {
            $officeId = $subject->office_id;
        } else {
            $officeId = app(OfficeContext::class)->id();
        }

        ActivityLog::create([
            'office_id' => $officeId,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload ?: null,
            'ip' => Request::ip(),
        ]);
    }
}
