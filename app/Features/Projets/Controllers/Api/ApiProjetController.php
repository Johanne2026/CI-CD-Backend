<?php

namespace App\Features\Projets\Controllers\Api;

use App\Features\Projets\Models\Projet;
use App\Features\Equipes\Models\MembreEquipe;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiProjetController extends Controller
{
    /**
     * Liste les projets.
     *
     * - Administrateur          : tous les projets.
     * - Autres rôles            : uniquement les projets des équipes dont l'utilisateur est membre.
     *
     * GET /api/projets
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'administrateur') {
            $projets = Projet::with([
                'equipe:id,nom',
                'creePar:id,nom,prenom',
            ])->get();
        } else {
            // Récupère les ids des équipes dont l'utilisateur est membre
            $equipeIds = MembreEquipe::where('utilisateur_id', $user->id)
                ->pluck('equipe_id');

            $projets = Projet::whereIn('equipe_id', $equipeIds)
                ->with([
                    'equipe:id,nom',
                    'creePar:id,nom,prenom',
                ])->get();
        }

        return response()->json($projets);
    }

    /**
     * Détail d'un projet.
     *
     * - Administrateur : accès à tous les projets.
     * - Autres rôles   : accès uniquement si membre de l'équipe du projet.
     *
     * GET /api/projets/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $projet = Projet::with([
            'equipe:id,nom',
            'creePar:id,nom,prenom',
        ])->findOrFail($id);

        if ($user->role !== 'administrateur') {
            $estMembre = MembreEquipe::where('equipe_id', $projet->equipe_id)
                ->where('utilisateur_id', $user->id)
                ->exists();

            if (! $estMembre) {
                return response()->json(['message' => 'Accès refusé.'], 403);
            }
        }

        return response()->json($projet);
    }

    /**
     * Crée un projet.
     * Une équipe ne peut avoir qu'un seul projet.
     *
     * POST /api/projets  [admin]
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'equipe_id'           => ['required', 'integer', 'exists:Equipes,id', 'unique:Projets,equipe_id'],
            'nom'                 => ['required', 'string', 'max:255'],
            'description'         => ['nullable', 'string'],
            'stack_technologique' => ['nullable', 'array'],
            'stack_technologique.*' => ['string'],
            'duree_projet'        => ['nullable', 'string', 'max:255'],
            'url_depot'           => ['nullable', 'string', 'url', 'max:500'],
        ]);

        $projet = Projet::create([
            'equipe_id'           => $request->equipe_id,
            'cree_par_id'         => $request->user()->id,
            'nom'                 => $request->nom,
            'description'         => $request->description,
            'stack_technologique' => $request->stack_technologique ?? [],
            'actif'               => true,
            'duree_projet'        => $request->duree_projet,
            'url_depot'           => $request->url_depot,
        ]);

        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json($projet, 201);
    }

    /**
     * Met à jour un projet.
     *
     * PUT /api/projets/{id}  [admin]
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        $request->validate([
            'nom'                   => ['sometimes', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'stack_technologique'   => ['nullable', 'array'],
            'stack_technologique.*' => ['string'],
            'duree_projet'          => ['nullable', 'string', 'max:255'],
            'url_depot'             => ['nullable', 'string', 'url', 'max:500'],
        ]);

        $projet->update($request->only(
            'nom',
            'description',
            'stack_technologique',
            'duree_projet',
            'url_depot'
        ));

        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json($projet);
    }

    /**
     * Archive ou réactive un projet (bascule le champ actif).
     *
     * PATCH /api/projets/{id}/archiver  [admin]
     */
    public function archiver(int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);
        $projet->update(['actif' => ! $projet->actif]);

        $message = $projet->actif ? 'Projet réactivé.' : 'Projet archivé.';

        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json([
            'message' => $message,
            'projet'  => $projet,
        ]);
    }

    /**
     * Connecte un projet à un dépôt GitHub.
     * Enregistre l'url_depot et les identifiants GitHub de l'utilisateur.
     *
     * POST /api/projets/{id}/connecter-depot
     */
    public function connecterDepot(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        $request->validate([
            'url_depot'           => ['required', 'string', 'url', 'max:500'],
            'username_outil_cicd' => ['required', 'string', 'max:255'],
            'token_outil_cicd'    => ['required', 'string', 'max:255'],
        ]);

        // Sauvegarde l'URL du dépôt sur le projet
        $projet->update(['url_depot' => $request->url_depot]);

        // Sauvegarde les identifiants GitHub sur l'utilisateur connecté
        $user = $request->user();
        $user->update([
            'username_outil_cicd' => $request->username_outil_cicd,
            'token_outil_cicd'    => $request->token_outil_cicd,
        ]);

        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json([
            'message' => 'Projet lié au dépôt GitHub avec succès.',
            'projet'  => $projet,
        ]);
    }

    /**
     * Supprime définitivement un projet.
     *
     * DELETE /api/projets/{id}  [admin]
     */
    public function destroy(int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);
        $projet->delete();

        return response()->json(['message' => 'Projet supprimé.']);
    }
}
