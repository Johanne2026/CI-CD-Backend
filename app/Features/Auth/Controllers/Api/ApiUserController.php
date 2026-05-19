<?php

namespace App\Features\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiUserController extends Controller
{
    /**
     * Return the authenticated user.
     * GET /api/user
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }
}
