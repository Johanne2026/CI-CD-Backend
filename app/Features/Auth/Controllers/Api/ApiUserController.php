<?php

namespace App\Features\Auth\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiUserController extends Controller
{
    /**
     * Retourne l'utilisateur authentifié.
     * GET /api/user
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    /**
     * Liste tous les utilisateurs (pour les sélecteurs dans les formulaires admin).
     * Retourne uniquement les champs nécessaires à l'affichage.
     *
     * GET /api/utilisateurs  [admin]
     */
    public function index(): JsonResponse
    {
        $utilisateurs = \App\Features\Auth\Models\User::select('id', 'nom', 'prenom', 'email', 'role')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        return response()->json($utilisateurs);
    }

    /**
     * Met à jour les informations de l'utilisateur authentifié.
     * Utilisé notamment pour la connexion GitHub (username_outil_cicd + token_outil_cicd).
     *
     * PUT /api/user
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'nom'                 => ['sometimes', 'string', 'max:255'],
            'prenom'              => ['sometimes', 'string', 'max:255'],
            // Unique mais ignore la ligne de l'utilisateur courant
            'username_outil_cicd' => ['sometimes', 'nullable', 'string', 'max:255',
                                      \Illuminate\Validation\Rule::unique('Utilisateurs', 'username_outil_cicd')->ignore($user->id)],
            'token_outil_cicd'    => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user->update($validated);

        // fresh() recharge depuis la BD — token_outil_cicd masqué via $hidden
        return response()->json($user->fresh());
    }
}
