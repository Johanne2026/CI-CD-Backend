<?php

namespace App\Features\Auth\Controllers\Api;

use App\Features\Auth\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;

class ApiAuthController extends Controller
{
    /**
     * Retourne l'URL de redirection selon le rôle de l'utilisateur.
     */
    private function redirectUrlForRole(string $role): string
    {
        return match ($role) {
            'administrateur'           => '/dashboard/admin',
            'administrateur_cloud_doi' => '/dashboard/cloud-doi',
            'securite'                 => '/dashboard/securite',
            default                    => '/dashboard',
        };
    }

    /**
     * Inscription — POST /api/register
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'nom'                  => ['required', 'string', 'max:255'],
            'prenom'               => ['required', 'string', 'max:255'],
            'username_outil_cicd'  => ['required', 'string', 'max:255', 'unique:Utilisateurs,username_outil_cicd'],
            'mot_de_passe'         => ['required', 'confirmed', Rules\Password::defaults()],
            'role'                 => ['required', 'string', 'in:administrateur,administrateur_cloud_doi,securite'],
        ]);

        $token = Str::random(60);

        $user = User::create([
            'nom'                 => $request->nom,
            'prenom'              => $request->prenom,
            'username_outil_cicd' => $request->username_outil_cicd,
            'mot_de_passe'        => $request->mot_de_passe,
            'api_token'           => hash('sha256', $token),
            'role'                => $request->role,
            'date_inscription'    => Carbon::now(),
        ]);

        return response()->json([
            'token'       => $token,
            'user'        => $user,
            'redirect_to' => $this->redirectUrlForRole($user->role),
        ], 201);
    }

    /**
     * Connexion — POST /api/login
     *
     * L'utilisateur se connecte avec son username_outil_cicd (GitHub) et son mot de passe.
     * Le token GitHub (token_outil_cicd) peut être fourni optionnellement à la connexion.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username_outil_cicd' => ['required', 'string'],
            'mot_de_passe'        => ['required', 'string'],
            'token_outil_cicd'    => ['nullable', 'string'],
        ]);

        $user = User::where('username_outil_cicd', $request->username_outil_cicd)->first();

        if (! $user || ! password_verify($request->mot_de_passe, $user->getAuthPassword())) {
            throw ValidationException::withMessages([
                'username_outil_cicd' => [__('auth.failed')],
            ]);
        }

        $token = Str::random(60);

        $updateData = ['api_token' => hash('sha256', $token)];

        // Stocke le token GitHub s'il est fourni à la connexion
        if ($request->filled('token_outil_cicd')) {
            $updateData['token_outil_cicd'] = $request->token_outil_cicd;
        }

        $user->update($updateData);

        return response()->json([
            'token'       => $token,
            'user'        => $user,
            'redirect_to' => $this->redirectUrlForRole($user->role),
        ]);
    }

    /**
     * Déconnexion — POST /api/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->update([
            'api_token'        => null,
            'token_outil_cicd' => null,
        ]);

        return response()->json(['message' => 'Déconnexion réussie.']);
    }
}
