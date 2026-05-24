<?php

namespace App\Features\Equipes\Controllers\Api;

use App\Features\Equipes\Models\Equipe;
use App\Features\Equipes\Models\MembreEquipe;
use App\Features\Auth\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApiEquipeController extends Controller
{
    /**
     * Liste les équipes.
     *
     * - Administrateur : toutes les équipes avec leurs membres.
     * - Autres rôles   : uniquement les équipes dont l'utilisateur est membre.
     *
     * GET /api/equipes
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'administrateur') {
            $equipes = Equipe::with([
                'proprietaire:id,nom,prenom,email',
                'membres:id,nom,prenom,email',
            ])->get();
        } else {
            $equipes = $user->equipes()->with([
                'proprietaire:id,nom,prenom,email',
                'membres:id,nom,prenom,email',
            ])->get();
        }

        return response()->json($equipes);
    }

    /**
     * Détail d'une équipe.
     *
     * - Administrateur : accès à toutes les équipes.
     * - Autres rôles   : accès uniquement si membre de l'équipe.
     *
     * GET /api/equipes/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user  = $request->user();
        $equipe = Equipe::with([
            'proprietaire:id,nom,prenom,email',
            'membres:id,nom,prenom,email',
        ])->findOrFail($id);

        if ($user->role !== 'administrateur') {
            $estMembre = MembreEquipe::where('equipe_id', $id)
                ->where('utilisateur_id', $user->id)
                ->exists();

            if (! $estMembre) {
                return response()->json(['message' => 'Accès refusé.'], 403);
            }
        }

        return response()->json($equipe);
    }

    /**
     * Crée une équipe et enregistre le propriétaire dans membre_equipe.
     *
     * POST /api/equipes  [admin]
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'proprietaire_id' => ['required', 'integer', 'exists:Utilisateurs,id'],
            'nom'             => ['required', 'string', 'max:255'],
            'description'     => ['nullable', 'string'],
        ]);

        $equipe = Equipe::create([
            'proprietaire_id' => $request->proprietaire_id,
            'nom'             => $request->nom,
            'description'     => $request->description,
        ]);

        // Enregistre le propriétaire dans membre_equipe avec le rôle "proprietaire"
        MembreEquipe::create([
            'utilisateur_id' => $request->proprietaire_id,
            'equipe_id'      => $equipe->id,
            'role'           => 'proprietaire',
            'date_adhesion'  => Carbon::now(),
        ]);

        $equipe->load([
            'proprietaire:id,nom,prenom,email',
            'membres:id,nom,prenom,email',
        ]);

        return response()->json($equipe, 201);
    }

    /**
     * Met à jour le nom ou la description d'une équipe.
     *
     * PUT /api/equipes/{id}  [admin]
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $equipe = Equipe::findOrFail($id);

        $request->validate([
            'nom'         => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $equipe->update($request->only('nom', 'description'));

        $equipe->load([
            'proprietaire:id,nom,prenom,email',
            'membres:id,nom,prenom,email',
        ]);

        return response()->json($equipe);
    }

    /**
     * Supprime une équipe (et ses entrées membre_equipe par cascade).
     *
     * DELETE /api/equipes/{id}  [admin]
     */
    public function destroy(int $id): JsonResponse
    {
        $equipe = Equipe::findOrFail($id);
        $equipe->delete();

        return response()->json(['message' => 'Équipe supprimée.']);
    }

    /**
     * Liste les utilisateurs disponibles pour être ajoutés à une équipe.
     * Exclut les utilisateurs déjà membres de l'équipe.
     *
     * GET /api/equipes/{id}/utilisateurs-disponibles  [admin]
     */
    public function utilisateursDispo(int $id): JsonResponse
    {
        // Vérifie que l'équipe existe
        $equipe = Equipe::findOrFail($id);

        // IDs des utilisateurs déjà membres
        $idsMembres = MembreEquipe::where('equipe_id', $id)
            ->pluck('utilisateur_id');

        // Tous les utilisateurs sauf ceux déjà membres
        $utilisateurs = User::whereNotIn('id', $idsMembres)
            ->select('id', 'nom', 'prenom', 'role')
            ->orderBy('nom')
            ->orderBy('prenom')
            ->get();

        return response()->json($utilisateurs);
    }

    /**
     * Ajoute un membre à une équipe.
     *
     * POST /api/equipes/{id}/membres  [admin]
     */
    public function ajouterMembre(Request $request, int $id): JsonResponse
    {
        $equipe = Equipe::findOrFail($id);

        $request->validate([
            'utilisateur_id' => ['required', 'integer', 'exists:Utilisateurs,id'],
        ]);

        $dejaMembre = MembreEquipe::where('equipe_id', $id)
            ->where('utilisateur_id', $request->utilisateur_id)
            ->exists();

        if ($dejaMembre) {
            return response()->json([
                'message' => 'Cet utilisateur est déjà membre de cette équipe.',
            ], 422);
        }

        MembreEquipe::create([
            'utilisateur_id' => $request->utilisateur_id,
            'equipe_id'      => $id,
            'role'           => 'membre',
            'date_adhesion'  => Carbon::now(),
        ]);

        $equipe->load([
            'proprietaire:id,nom,prenom,email',
            'membres:id,nom,prenom,email',
        ]);

        return response()->json($equipe, 201);
    }

    /**
     * Retire un membre d'une équipe.
     * Le propriétaire ne peut pas être retiré.
     *
     * DELETE /api/equipes/{id}/membres/{userId}  [admin]
     */
    public function retirerMembre(int $id, int $userId): JsonResponse
    {
        $equipe = Equipe::findOrFail($id);

        if ($equipe->proprietaire_id === $userId) {
            return response()->json([
                'message' => 'Le propriétaire ne peut pas être retiré de son équipe.',
            ], 422);
        }

        $supprime = MembreEquipe::where('equipe_id', $id)
            ->where('utilisateur_id', $userId)
            ->delete();

        if (! $supprime) {
            return response()->json(['message' => 'Membre introuvable dans cette équipe.'], 404);
        }

        return response()->json(['message' => 'Membre retiré de l\'équipe.']);
    }
}
