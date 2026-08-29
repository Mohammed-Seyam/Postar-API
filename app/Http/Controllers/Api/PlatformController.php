<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Platforms\PlatformCapabilities;

class PlatformController extends Controller
{
    /**
     * Expose the platform capability registry so the frontend can drive its
     * platform selection and media rules without hardcoding them.
     */
    public function index()
    {
        $platforms = collect(PlatformCapabilities::all())
            ->map(function (array $caps, string $slug) {
                return array_merge(['slug' => $slug], $caps);
            })
            ->values();

        return response()->json(['data' => $platforms]);
    }
}
