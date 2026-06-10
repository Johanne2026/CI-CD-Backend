<?php

namespace App\Features\Projets\Controllers\Api;

use App\Features\Projets\Models\Projet;
use App\Features\Equipes\Models\MembreEquipe;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
     * Connecte un projet à un dépôt GitHub.
     * Enregistre l'url_depot sur le projet.
     * Le token GitHub est maintenant dans GITHUB_TOKEN (.env) — plus dans l'utilisateur.
     *
     * POST /api/projets/{id}/connecter-depot
     */
    public function connecterDepot(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        $request->validate([
            'url_depot'           => ['required', 'string', 'url', 'max:500'],
            'username_outil_cicd' => ['required', 'string', 'max:255'],
        ]);

        // Sauvegarde l'URL du dépôt sur le projet
        $projet->update(['url_depot' => $request->url_depot]);

        // Met à jour le nom d'utilisateur GitHub (pas le token — il est dans .env)
        $user = $request->user();
        $user->update([
            'username_outil_cicd' => $request->username_outil_cicd,
        ]);

        $projet->load(['equipe:id,nom', 'creePar:id,nom,prenom']);

        return response()->json([
            'message' => 'Projet lié au dépôt GitHub avec succès.',
            'projet'  => $projet,
        ]);
    }

    /**
     * Génère une clé de déploiement pour le projet et la publie dans
     * GitHub Repository Secrets sous le nom DEPLOY_KEY.
     * Utilise l'API GitHub REST directement via Http:: (pas de package tiers).
     *
     * POST /api/projets/{id}/generer-cle-deploiement  [admin]
     */
    public function genererCleDeploiement(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        if (! $projet->url_depot) {
            return response()->json([
                'message' => 'Le projet doit être lié à un dépôt GitHub avant de générer une clé.',
            ], 422);
        }

        $githubToken = config('services.github.token');
        if (! $githubToken) {
            return response()->json([
                'message' => 'GITHUB_TOKEN non configuré dans .env.',
            ], 500);
        }

        $cleDeploiement = Str::random(64);

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $http = Http::withToken($githubToken)
            ->withHeaders([
                'Accept'               => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ])
            ->timeout(30);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        // ── 1. Récupérer la clé publique du dépôt ─────────────────────────────
        $reponsePublicKey = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/secrets/public-key"
        );

        if ($reponsePublicKey->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
        }

        if (! $reponsePublicKey->successful()) {
            return response()->json([
                'message' => 'Impossible de récupérer la clé publique du dépôt GitHub.',
                'details' => $reponsePublicKey->json('message'),
            ], 502);
        }

        $publicKeyBase64 = $reponsePublicKey->json('key');
        $keyId           = $reponsePublicKey->json('key_id');

        // ── 2. Chiffrer la clé avec libsodium (requis par GitHub) ─────────────
        $encryptedValue = $this->chiffrerSecret($cleDeploiement, $publicKeyBase64);

        // ── 3. Créer/mettre à jour le secret DEPLOY_KEY ────────────────────────
        $reponsePut = $http->put(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/secrets/DEPLOY_KEY",
            [
                'encrypted_value' => $encryptedValue,
                'key_id'          => $keyId,
            ]
        );

        if (! $reponsePut->successful() && $reponsePut->status() !== 204) {
            return response()->json([
                'message' => 'Erreur lors de la création du secret GitHub : ' . $reponsePut->json('message'),
            ], 502);
        }

        // Le nom de la clé = nom du dépôt GitHub en minuscules (ex: intern-assetquickview-project)
        $nomCle = strtolower($repo);

        // ── 4. Sauvegarder en base ─────────────────────────────────────────────
        $projet->update(['cle_deploiement' => $cleDeploiement]);

        // ── 5. Synchroniser deploy-keys.json sur la VM ─────────────────────────
        $this->syncDeployKeys($nomCle, $cleDeploiement);

        return response()->json([
            'message'         => "Clé de déploiement générée et publiée dans {$owner}/{$repo} (secret DEPLOY_KEY).",
            'cle_deploiement' => $cleDeploiement,
        ]);
    }

    /**
     * Synchronise deploy-keys.json avec la nouvelle clé de déploiement.
     * Lit le fichier existant, ajoute/met à jour l'entrée du projet, et sauvegarde.
     * Le fichier est utilisé par le CD (pipeline sur la VM) pour vérifier les clés.
     */
    private function syncDeployKeys(string $nomProjet, string $cleDeploiement): void
    {
        $fichier = config('deploy.keys_file', 'C:\\Deploy\\Security\\deploy-keys.json');

        // Sur un chemin UNC (\\tsclient\...) le dossier doit exister au préalable sur la VM.
        // Créer uniquement si le chemin est local (pas UNC).
        $dossier = dirname($fichier);
        if (! str_starts_with($fichier, '\\\\') && ! is_dir($dossier)) {
            mkdir($dossier, 0755, true);
        }

        if (! is_dir($dossier) && ! str_starts_with($fichier, '\\\\')) {
            // Dossier inaccessible — on log et on continue sans bloquer
            \Illuminate\Support\Facades\Log::warning('syncDeployKeys: dossier inaccessible', [
                'fichier' => $fichier,
            ]);
            return;
        }

        $data = [];
        if (file_exists($fichier)) {
            $contenu = file_get_contents($fichier);
            $decoded = json_decode($contenu, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $data[$nomProjet] = $cleDeploiement;

        file_put_contents($fichier, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Chiffre un secret avec la clé publique du dépôt GitHub (libsodium).
     * GitHub exige ce chiffrement pour créer des Repository Secrets.
     */
    private function chiffrerSecret(string $valeur, string $publicKeyBase64): string
    {
        $publicKey      = base64_decode($publicKeyBase64);
        $valeurBytes    = mb_convert_encoding($valeur, 'UTF-8');
        $encryptedBytes = sodium_crypto_box_seal($valeurBytes, $publicKey);

        return base64_encode($encryptedBytes);
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
