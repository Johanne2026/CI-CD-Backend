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
     * Liste les projets actifs (actif = true).
     * Les projets archivés ne sont jamais retournés — ils restent en BD.
     *
     * - Administrateur          : tous les projets actifs.
     * - Autres rôles            : uniquement les projets actifs des équipes dont l'utilisateur est membre.
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
            ])->where('actif', true)->get();
        } else {
            $equipeIds = MembreEquipe::where('utilisateur_id', $user->id)
                ->pluck('equipe_id');

            $projets = Projet::whereIn('equipe_id', $equipeIds)
                ->where('actif', true)
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
     * Une équipe peut avoir plusieurs projets.
     *
     * POST /api/projets  [admin]
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'equipe_id'             => ['required', 'integer', 'exists:Equipes,id'],
            'nom'                   => ['required', 'string', 'max:255'],
            'description'           => ['nullable', 'string'],
            'stack_technologique'   => ['nullable', 'array'],
            'stack_technologique.*' => ['string'],
            'duree_projet'          => ['nullable', 'string', 'max:255'],
            'url_depot'             => ['nullable', 'string', 'url', 'max:500'],
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
     * Connecte un projet à un dépôt GitHub — réservé à l'administrateur.
     *
     * Si l'admin n'a pas encore configuré ses credentials GitHub (username + token),
     * il doit les fournir dans le body. Ils sont sauvegardés en BD :
     * - username_outil_cicd en clair
     * - token_github chiffré AES-256 via le cast "encrypted" du modèle
     *
     * Le backend utilise ensuite le token stocké pour toutes les requêtes GitHub.
     *
     * POST /api/projets/{id}/connecter-depot  [admin]
     */
    public function connecterDepot(Request $request, int $id): JsonResponse
    {
        $user   = $request->user();
        $projet = Projet::findOrFail($id);

        // Seul l'administrateur peut lier un dépôt GitHub
        if ($user->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé. Seul l\'administrateur peut lier un dépôt GitHub.'], 403);
        }

        // Déterminer si les credentials sont déjà configurés
        $aUsername = ! empty($user->username_outil_cicd);
        $aToken    = ! empty($user->token_github);

        // Règles de validation — username et token obligatoires si pas encore configurés
        $rules = [
            'url_depot' => ['required', 'string', 'url', 'max:500'],
        ];

        if (! $aUsername) {
            $rules['username_outil_cicd'] = ['required', 'string', 'max:255'];
        } else {
            $rules['username_outil_cicd'] = ['sometimes', 'nullable', 'string', 'max:255'];
        }

        if (! $aToken) {
            $rules['token_github'] = [
                'required', 'string', 'max:255',
                'regex:/^gh[ps]_[A-Za-z0-9_]+$/',
            ];
        } else {
            $rules['token_github'] = [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:/^gh[ps]_[A-Za-z0-9_]+$/',
            ];
        }

        $request->validate($rules);

        // Sauvegarder les credentials si fournis (ou si absent → erreur déjà gérée par validate)
        $miseAJourUser = [];

        if ($request->filled('username_outil_cicd')) {
            $miseAJourUser['username_outil_cicd'] = $request->username_outil_cicd;
        }

        if ($request->filled('token_github')) {
            $miseAJourUser['token_github'] = $request->token_github; // chiffré par cast "encrypted"
        }

        if (! empty($miseAJourUser)) {
            $user->update($miseAJourUser);
        }

        // Vérification finale — à ce stade l'admin doit obligatoirement avoir ses credentials
        if (! $user->fresh()->aConfigureGithub()) {
            return response()->json([
                'message'       => 'Credentials GitHub manquants.',
                'manque_github' => true,
                'a_username'    => ! empty($user->username_outil_cicd),
                'a_token'       => ! empty($user->token_github),
            ], 422);
        }

        // Sauvegarder l'URL du dépôt sur le projet
        $projet->update(['url_depot' => $request->url_depot]);
        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json([
            'message'      => 'Projet lié au dépôt GitHub avec succès.',
            'projet'       => $projet,
            'credentials_sauvegardes' => ! empty($miseAJourUser),
        ]);
    }

    /**
     * Extrait owner/repo depuis une URL GitHub.
     */
    private function parseDepotUrl(string $url): array
    {
        $url   = rtrim($url, '/');
        $url   = preg_replace('/\.git$/', '', $url);
        $parts = array_values(array_filter(explode('/', parse_url($url, PHP_URL_PATH))));

        if (count($parts) < 2) {
            throw new \InvalidArgumentException('URL du dépôt GitHub invalide.');
        }

        return [$parts[count($parts) - 2], $parts[count($parts) - 1]];
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
