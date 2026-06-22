<?php

namespace App\Features\Auth\Controllers\Api;

use App\Features\Auth\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ApiUserController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/user — profil de l'utilisateur connecté
    // -------------------------------------------------------------------------

    public function show(Request $request): JsonResponse
    {
        return response()->json($request->user());
    }

    // -------------------------------------------------------------------------
    // GET /api/user/github-status — statut des credentials GitHub [admin]
    // -------------------------------------------------------------------------

    /**
     * Indique si l'administrateur a configuré son nom d'utilisateur GitHub
     * et son token personnel. Le token n'est jamais retourné en clair.
     */
    public function githubStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        return response()->json([
            'a_username'     => ! empty($user->username_outil_cicd),
            'a_token'        => ! empty($user->token_github),
            'configure'      => $user->aConfigureGithub(),
            'username'       => $user->username_outil_cicd,
            // Le token n'est JAMAIS retourné — uniquement un indicateur booléen
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/utilisateurs — liste tous les utilisateurs [admin]
    // -------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        $utilisateurs = User::select(
            'id', 'nom', 'prenom', 'email', 'role',
            'date_inscription', 'doit_changer_mot_de_passe', 'created_at'
        )
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        return response()->json($utilisateurs);
    }

    // -------------------------------------------------------------------------
    // POST /api/utilisateurs — créer un utilisateur [admin]
    // -------------------------------------------------------------------------

    /**
     * L'administrateur crée un utilisateur avec un mot de passe par défaut.
     * L'utilisateur devra changer son mot de passe à la première connexion.
     *
     * Seuls les rôles administrateur_cloud_doi et securite peuvent être créés ici.
     * (L'administrateur se crée via /register)
     *
     * Un mot de passe temporaire est généré automatiquement et retourné
     * dans la réponse pour être communiqué à l'utilisateur.
     */
    public function creer(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        $request->validate([
            'nom'    => ['required', 'string', 'max:255'],
            'prenom' => ['required', 'string', 'max:255'],
            'email'  => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:Utilisateurs,email'],
            'role'   => ['required', 'string', 'in:administrateur_cloud_doi,securite'],
        ]);

        // Générer un mot de passe temporaire sécurisé de 12 caractères
        $motDePasseTemporaire = Str::password(12, letters: true, numbers: true, symbols: false);

        $user = User::create([
            'nom'                       => $request->nom,
            'prenom'                    => $request->prenom,
            'email'                     => $request->email,
            'mot_de_passe'              => $motDePasseTemporaire,
            'role'                      => $request->role,
            'doit_changer_mot_de_passe' => true,  // force le changement à la première connexion
            'date_inscription'          => Carbon::now(),
        ]);

        return response()->json([
            'message'              => "Utilisateur \"{$user->nom} {$user->prenom}\" créé avec succès.",
            'utilisateur'          => $user->only('id', 'nom', 'prenom', 'email', 'role', 'doit_changer_mot_de_passe'),
            'mot_de_passe_temporaire' => $motDePasseTemporaire,  // à communiquer à l'utilisateur
        ], 201);
    }

    // -------------------------------------------------------------------------
    // PUT /api/user — mise à jour du profil de l'utilisateur connecté
    // -------------------------------------------------------------------------

    /**
     * Champs modifiables : nom, prenom, mot de passe
     * Champ NON modifiable : email (lecture seule côté frontend)
     * username_outil_cicd : uniquement pour les administrateurs
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $rules = [
            'nom'    => ['sometimes', 'string', 'max:255'],
            'prenom' => ['sometimes', 'string', 'max:255'],
            'mot_de_passe_actuel'               => ['sometimes', 'required_with:nouveau_mot_de_passe', 'string'],
            'nouveau_mot_de_passe'              => ['sometimes', 'required_with:mot_de_passe_actuel', 'string', 'min:8', 'confirmed'],
            'nouveau_mot_de_passe_confirmation' => ['sometimes', 'string'],
        ];

        // username_outil_cicd et token_github uniquement pour l'administrateur
        if ($user->role === 'administrateur') {
            $rules['username_outil_cicd'] = [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::unique('Utilisateurs', 'username_outil_cicd')->ignore($user->id),
            ];
            $rules['token_github'] = [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:/^gh[ps]_[A-Za-z0-9_]+$/',  // format token GitHub
            ];
        }

        $validated = $request->validate($rules);

        // Vérifier le mot de passe actuel avant changement
        if ($request->filled('mot_de_passe_actuel')) {
            if (! Hash::check($request->mot_de_passe_actuel, $user->mot_de_passe)) {
                return response()->json([
                    'message' => 'Le mot de passe actuel est incorrect.',
                    'errors'  => ['mot_de_passe_actuel' => ['Le mot de passe actuel est incorrect.']],
                ], 422);
            }

            $validated['mot_de_passe']              = Hash::make($request->nouveau_mot_de_passe);
            $validated['doit_changer_mot_de_passe'] = false;  // mot de passe changé → plus obligatoire
        }

        unset(
            $validated['mot_de_passe_actuel'],
            $validated['nouveau_mot_de_passe'],
            $validated['nouveau_mot_de_passe_confirmation']
        );

        $user->update($validated);

        return response()->json($user->fresh());
    }
}
