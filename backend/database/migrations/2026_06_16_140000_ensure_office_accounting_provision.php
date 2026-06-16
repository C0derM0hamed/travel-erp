<?php

use App\Models\Office;
use App\Services\OfficeProvisioningService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $service = app(OfficeProvisioningService::class);

        foreach (Office::withoutGlobalScopes()->orderBy('id')->pluck('id') as $officeId) {
            $service->ensureOfficeProvisioned((int) $officeId);
        }
    }

    public function down(): void
    {
        // Non-destructive provisioning backfill.
    }
};
