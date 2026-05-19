<?php

namespace App\Features\Auth\Controllers\Api;

use App\Features\Auth\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class ApiAuthController extends Controller
{
    /**
     * Inscription — POST /api/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'nom'          => ['required', 'string', 'max:255'],
            'email'        => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
            'mot_de_passe' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $token = Str::random(60);

        $user = User::create([
            'nom'          => $request->nom,
            'email'        => $request->email,
            'mot_de_passe' => $request->mot_de_passe,
            'api_token'    => hash('sha256', $token),
        ]);

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ], 201);
    }

    /**
     * Connexion — POST /api/login
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'        => ['required', 'string', 'email'],
            'mot_de_passe' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! password_verify($request->mot_de_passe, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        $token = Str::random(60);

        $user->update(['api_token' => hash('sha256', $token)]);

        return response()->json([
            'token' => $token,
            'user'  => $user,
        ]);
    }

    /**
     * Déconnexion — POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->update(['api_token' => null]);

        return response()->json(['message' => 'Déconnexion réussie.']);
    }
}
