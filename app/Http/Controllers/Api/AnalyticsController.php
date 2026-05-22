<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends Controller
{
    public function show(Request $request, string $any): JsonResponse
    {
        // Dummy implementation for now to satisfy the routing requirements
        // and allow testing of the plan:analytics middleware limit check.
        return response()->json([
            'message' => 'Analytics payload for ' . $any,
            'data' => []
        ]);
    }
}
