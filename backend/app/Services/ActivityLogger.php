<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Office;
use App\Models\Operation;
use App\Models\User;
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

        $userId = $userId ?? auth()->id();
        $payload = $this->enrichPayload($action, $subject, $payload, $userId, $officeId);

        ActivityLog::create([
            'office_id' => $officeId,
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'payload' => $payload ?: null,
            'ip' => Request::ip(),
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function enrichPayload(string $action, ?Model $subject, array $payload, ?int $userId, ?int $officeId): array
    {
        if ($subject instanceof Operation) {
            $payload['ref'] = $payload['ref'] ?? $subject->ref;
        }

        if ($userId && empty($payload['user_name'])) {
            $payload['user_name'] = User::find($userId)?->name;
        }

        if ($officeId && empty($payload['office_name'])) {
            $payload['office_name'] = Office::find($officeId)?->office_name;
        }

        if (str_starts_with($action, 'operation.') && empty($payload['entity'])) {
            $payload['entity'] = 'operation';
        }

        return $payload;
    }
}
