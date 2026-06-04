<?php

namespace App\Http\Controllers\Api;

use App\Models\Service;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ServiceController extends ApiController
{
    public function toggle(Service $service): JsonResponse
    {
        Gate::authorize('update', $service);

        $service->update(['active' => ! $service->active]);
        app(ActivityLogger::class)->log('service.toggled', $service, ['active' => $service->active]);

        return response()->json($service);
    }
}
