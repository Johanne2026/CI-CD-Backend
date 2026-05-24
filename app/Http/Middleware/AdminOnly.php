<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOnly
{
    /**
     * Autorise uniquement les utilisateurs avec le rôle "administrateur".
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->role !== 'administrateur') {
            return response()->json([
                'message' => 'Accès refusé. Cette action est réservée aux administrateurs.',
            ], 403);
        }

        return $next($request);
    }
}
