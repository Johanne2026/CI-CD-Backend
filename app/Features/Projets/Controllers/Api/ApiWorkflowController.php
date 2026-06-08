<?php

namespace App\Features\Projets\Controllers\Api;

use App\Features\Projets\Models\Projet;
use App\Features\Equipes\Models\MembreEquipe;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApiWorkflowController extends Controller
{
    /**
     * Extrait owner/repo depuis une URL GitHub.
     * Ex: https://github.com/owner/repo → ['owner', 'repo']
     */
    private function parseDepotUrl(string $url): array
    {
        // Supprime .git en fin d'URL si présent
        $url = rtrim($url, '/');
        $url = preg_replace('/\.git$/', '', $url);

        $parts = explode('/', parse_url($url, PHP_URL_PATH));
        $parts = array_values(array_filter($parts));

        if (count($parts) < 2) {
            throw new \InvalidArgumentException('URL du dépôt GitHub invalide.');
        }

        return [$parts[count($parts) - 2], $parts[count($parts) - 1]];
    }

    /**
     * Vérifie que l'utilisateur a accès au projet.
     */
    private function verifierAcces(Request $request, Projet $projet): bool
    {
        $user = $request->user();

        if ($user->role === 'administrateur') {
            return true;
        }

        return MembreEquipe::where('equipe_id', $projet->equipe_id)
            ->where('utilisateur_id', $user->id)
            ->exists();
    }

    /**
     * Synchronise et retourne tous les workflows (pipelines) du dépôt GitHub lié.
     * Fonctionne sans token pour les dépôts publics.
     * Utilise le token si disponible pour éviter la limite de 60 req/h.
     *
     * POST /api/projets/{id}/workflows/sync
     */
    public function sync(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json([
                'message' => 'Ce projet n\'est pas lié à un dépôt GitHub.',
            ], 422);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Utilise le token si disponible, sinon requête publique
        $http = Http::withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->timeout(30);

        // Proxy si configuré dans .env
        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        $token = $request->user()->token_outil_cicd;
        if ($token) {
            $http = $http->withToken($token);
        }

        $response = $http->get("https://api.github.com/repos/{$owner}/{$repo}/actions/workflows");

        if ($response->status() === 401) {
            return response()->json([
                'message' => 'Token GitHub invalide ou expiré. Veuillez reconnecter votre compte GitHub.',
            ], 401);
        }

        if ($response->status() === 404) {
            return response()->json([
                'message' => "Dépôt introuvable : {$owner}/{$repo}. Vérifiez l'URL du dépôt.",
            ], 404);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Erreur lors de la communication avec GitHub.',
                'details' => $response->json('message'),
            ], $response->status());
        }

        $data      = $response->json();
        $workflows = collect($data['workflows'] ?? [])->map(fn ($w) => [
            'id'         => $w['id'],
            'nom'        => $w['name'],
            'fichier'    => $w['path'],
            'etat'       => $w['state'],
            'url_github' => $w['html_url'],
            'badge_url'  => $w['badge_url'],
            'created_at' => $w['created_at'],
            'updated_at' => $w['updated_at'],
        ])->values();

        return response()->json([
            'depot'     => "{$owner}/{$repo}",
            'total'     => $data['total_count'] ?? count($workflows),
            'workflows' => $workflows,
        ]);
    }

    /**
     * Retourne les exécutions (runs) d'un workflow spécifique.
     *
     * GET /api/projets/{id}/workflows/{workflowId}/runs
     */
    public function runs(Request $request, int $id, int $workflowId): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json([
                'message' => 'Ce projet n\'est pas lié à un dépôt GitHub.',
            ], 422);
        }

        $user = $request->user();

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $http  = Http::withHeaders(['Accept' => 'application/vnd.github+json'])
                     ->timeout(30);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        $token = $user->token_outil_cicd;
        if ($token) {
            $http = $http->withToken($token);
        }

        $response = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/workflows/{$workflowId}/runs",
            ['per_page' => 10]
        );

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Erreur GitHub.',
                'details' => $response->json('message'),
            ], $response->status());
        }

        $data = $response->json();
        $runs = collect($data['workflow_runs'] ?? [])->map(fn ($r) => [
            'id'          => $r['id'],
            'nom'         => $r['name'],
            'statut'      => $r['status'],       // queued | in_progress | completed
            'conclusion'  => $r['conclusion'],   // success | failure | cancelled | null
            'branche'     => $r['head_branch'],
            'commit_sha'  => substr($r['head_sha'], 0, 7),
            'declencheur' => $r['event'],         // push | pull_request | workflow_dispatch...
            'url_github'  => $r['html_url'],
            'debut'       => $r['run_started_at'],
            'fin'         => $r['updated_at'],
        ])->values();

        return response()->json([
            'total' => $data['total_count'] ?? count($runs),
            'runs'  => $runs,
        ]);
    }

    /**
     * Retourne la liste des templates GitHub Actions officiels.
     * Source : dépôt public actions/starter-workflows (catégorie CI).
     * Accessible sans token — dépôt public.
     *
     * GET /api/workflows/templates
     */
    public function templates(Request $request): JsonResponse
    {
        $categorie = $request->query('categorie', 'ci'); // ci | deployments | automation | pages | code-scanning

        $http = Http::withHeaders([
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'Laravel-CICD-App',
        ])->timeout(15);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        // Utilise le token de l'utilisateur si disponible (évite la limite 60 req/h)
        $token = $request->user()?->token_outil_cicd;
        if ($token) {
            $http = $http->withToken($token);
        }

        $response = $http->get(
            "https://api.github.com/repos/actions/starter-workflows/contents/{$categorie}"
        );

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Impossible de récupérer les templates GitHub.',
                'details' => $response->json('message'),
            ], $response->status());
        }

        $templates = collect($response->json())
            ->filter(fn ($item) => $item['type'] === 'file' && str_ends_with($item['name'], '.yml'))
            ->map(fn ($item) => [
                'nom'          => str_replace('.yml', '', $item['name']),
                'fichier'      => $item['name'],
                'url_github'   => $item['html_url'],
                'download_url' => $item['download_url'],
                'sha'          => $item['sha'],
            ])
            ->values();

        return response()->json([
            'categorie' => $categorie,
            'total'     => count($templates),
            'templates' => $templates,
        ]);
    }

    /**
     * Retourne le contenu YAML d'un template spécifique.
     *
     * GET /api/workflows/templates/{fichier}
     */
    public function templateContenu(Request $request, string $fichier): JsonResponse
    {
        if (! str_ends_with($fichier, '.yml')) {
            $fichier .= '.yml';
        }

        $categorie = $request->query('categorie', 'ci');

        $http = Http::withHeaders([
            'Accept'     => 'application/vnd.github.raw+json',
            'User-Agent' => 'Laravel-CICD-App',
        ])->timeout(15);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        $token = $request->user()?->token_outil_cicd;
        if ($token) {
            $http = $http->withToken($token);
        }

        $response = $http->get(
            "https://raw.githubusercontent.com/actions/starter-workflows/main/{$categorie}/{$fichier}"
        );

        if ($response->status() === 404) {
            return response()->json([
                'message' => "Template introuvable : {$fichier}",
            ], 404);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Impossible de récupérer le contenu du template.',
            ], $response->status());
        }

        return response()->json([
            'fichier'      => $fichier,
            'yaml_content' => $response->body(),
        ]);
    }

    /**
     * Crée un workflow dans le dépôt GitHub depuis un template YAML.
     * Si le fichier existe déjà, il est mis à jour (nécessite le SHA).
     *
     * POST /api/projets/{id}/workflows/depuis-template
     */
    public function depuisTemplate(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json([
                'message' => 'Ce projet n\'a pas de dépôt GitHub lié.',
            ], 422);
        }

        $user  = $request->user();
        $token = $user->token_outil_cicd;

        if (! $token) {
            return response()->json([
                'message' => 'Token GitHub non configuré. Connectez GitHub depuis le projet.',
            ], 422);
        }

        $request->validate([
            'workflow_name' => ['required', 'string', 'max:255', 'ends_with:.yml'],
            'yaml_content'  => ['required', 'string'],
            'branch'        => ['sometimes', 'string', 'max:255'],
        ]);

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $branch       = $request->input('branch', 'main');
        $workflowName = $request->workflow_name;
        $path         = ".github/workflows/{$workflowName}";
        $content      = base64_encode($request->yaml_content);

        $http = Http::withToken($token)
                    ->withHeaders([
                        'Accept'               => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->timeout(30);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        // Vérifie si le fichier existe déjà pour récupérer son SHA
        // (GitHub exige le SHA pour mettre à jour un fichier existant)
        $sha      = null;
        $existing = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}",
            ['ref' => $branch]
        );

        if ($existing->successful()) {
            $sha = $existing->json('sha');
        }

        // Prépare le payload
        $payload = [
            'message' => "ci: add {$workflowName} workflow",
            'content' => $content,
            'branch'  => $branch,
        ];

        if ($sha) {
            $payload['sha']     = $sha;
            $payload['message'] = "ci: update {$workflowName} workflow";
        }

        // Crée ou met à jour le fichier dans le dépôt
        $response = $http->put(
            "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}",
            $payload
        );

        if ($response->status() === 401) {
            return response()->json([
                'message' => 'Token GitHub invalide ou expiré. Veuillez reconnecter votre compte GitHub.',
            ], 401);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'GitHub : ' . ($response->json('message') ?? 'Erreur inconnue.'),
                'details' => $response->json('errors'),
            ], $response->status());
        }

        $action = $sha ? 'mis à jour' : 'créé';

        return response()->json([
            'message'       => "Workflow \"{$workflowName}\" {$action} dans {$owner}/{$repo}.",
            'path'          => $path,
            'url_github'    => "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}",
            'workflow_name' => $workflowName,
            'branch'        => $branch,
        ]);
    }
}
