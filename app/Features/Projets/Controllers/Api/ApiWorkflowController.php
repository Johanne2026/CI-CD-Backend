<?php

namespace App\Features\Projets\Controllers\Api;

use App\Features\Auth\Models\User;
use App\Features\Equipes\Models\MembreEquipe;
use App\Features\Notifications\Services\NotificationService;
use App\Features\Projets\Models\PipelinePret;
use App\Features\Projets\Models\Projet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ApiWorkflowController extends Controller
{
    /**
     * Retourne le token GitHub Ã  utiliser pour l'utilisateur connectÃ©.
     * - Si l'utilisateur est administrateur et a un token_github en BD â†’ l'utiliser
     * - Sinon â†’ fallback sur GITHUB_TOKEN dans .env
     */
    private function githubToken(Request $request = null): string
    {
        if ($request && $request->user() && $request->user()->role === 'administrateur') {
            return $request->user()->tokenGithubEffectif();
        }
        return config('services.github.token', '');
    }

    /**
     * Construit un client Http:: prÃ©configurÃ© pour l'API GitHub.
     */
    private function githubHttp(string $token = ''): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::withHeaders([
            'Accept'               => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'           => 'Laravel-CICD-App',
            'Accept-Encoding'      => 'gzip, deflate',
        ])->timeout(60);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        if ($token) {
            $http = $http->withToken($token);
        }

        return $http;
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
            throw new \InvalidArgumentException('URL du dÃ©pÃ´t GitHub invalide.');
        }

        return [$parts[count($parts) - 2], $parts[count($parts) - 1]];
    }

    /**
     * VÃ©rifie que l'utilisateur a accÃ¨s au projet.
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
     * Synchronise et retourne tous les workflows du dÃ©pÃ´t GitHub liÃ©.
     * POST /api/projets/{id}/workflows/sync
     */
    public function sync(Request $request, string $id): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas liÃ© Ã  un dÃ©pÃ´t GitHub.'], 422);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp($this->githubToken($request))
            ->get("https://api.github.com/repos/{$owner}/{$repo}/actions/workflows");

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expirÃ©.'], 401);
        }

        if ($response->status() === 404) {
            return response()->json([
                'message' => "DÃ©pÃ´t introuvable : {$owner}/{$repo}.",
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
     * Retourne les exÃ©cutions d'un workflow spÃ©cifique.
     * GET /api/projets/{id}/workflows/{workflowId}/runs
     */
    public function runs(Request $request, string $id, string $workflowId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas liÃ© Ã  un dÃ©pÃ´t GitHub.'], 422);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp($this->githubToken($request))->get(
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
            'statut'      => $r['status'],
            'conclusion'  => $r['conclusion'],
            'branche'     => $r['head_branch'],
            'commit_sha'  => substr($r['head_sha'], 0, 7),
            'declencheur' => $r['event'],
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
     * GET /api/workflows/templates
     */
    public function templates(Request $request): JsonResponse
    {
        $categorie = $request->query('categorie', 'ci');

        $response = $this->githubHttp($this->githubToken($request))->get(
            "https://api.github.com/repos/actions/starter-workflows/contents/{$categorie}"
        );

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Impossible de rÃ©cupÃ©rer les templates GitHub.',
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
     * Retourne le contenu YAML d'un template spÃ©cifique.
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

        $token = $this->githubToken($request);
        if ($token) {
            $http = $http->withToken($token);
        }

        $response = $http->get(
            "https://raw.githubusercontent.com/actions/starter-workflows/main/{$categorie}/{$fichier}"
        );

        if ($response->status() === 404) {
            return response()->json(['message' => "Template introuvable : {$fichier}"], 404);
        }

        if (! $response->successful()) {
            return response()->json(['message' => 'Impossible de rÃ©cupÃ©rer le contenu du template.'], $response->status());
        }

        return response()->json([
            'fichier'      => $fichier,
            'yaml_content' => $response->body(),
        ]);
    }

    /**
     * Retourne les artifacts d'une exÃ©cution spÃ©cifique.
     * GET /api/projets/{id}/workflows/runs/{runId}/artifacts
     */
    public function artifacts(Request $request, string $id, string $runId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'AccÃ¨s refusÃ©. RÃ©servÃ© aux administrateurs.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas liÃ© Ã  un dÃ©pÃ´t GitHub.'], 422);
        }

        if (! $this->githubToken($request)) {
            return response()->json(['message' => 'GITHUB_TOKEN non configurÃ© dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp($this->githubToken($request))->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/artifacts"
        );

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expirÃ©.'], 401);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Erreur GitHub.',
                'details' => $response->json('message'),
            ], $response->status());
        }

        $data      = $response->json();
        $artifacts = collect($data['artifacts'] ?? [])->map(fn ($a) => [
            'id'         => $a['id'],
            'nom'        => $a['name'],
            'taille'     => $a['size_in_bytes'],
            'expire_at'  => $a['expires_at'],
            'created_at' => $a['created_at'],
            'expired'    => $a['expired'],
        ])->values();

        return response()->json([
            'run_id'    => $runId,
            'total'     => $data['total_count'] ?? count($artifacts),
            'artifacts' => $artifacts,
        ]);
    }

    /**
     * GÃ©nÃ¨re une URL de tÃ©lÃ©chargement signÃ©e pour un artifact.
     * GET /api/projets/{id}/workflows/artifacts/{artifactId}/download
     */
    public function downloadArtifact(Request $request, string $id, string $artifactId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'AccÃ¨s refusÃ©. RÃ©servÃ© aux administrateurs.'], 403);
        }

        if (! $this->githubToken($request)) {
            return response()->json(['message' => 'GITHUB_TOKEN non configurÃ© dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $http = $this->githubHttp($this->githubToken($request))->withoutRedirecting();

        $response = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/artifacts/{$artifactId}/zip"
        );

        if ($response->status() === 302) {
            return response()->json(['download_url' => $response->header('Location')]);
        }

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expirÃ©.'], 401);
        }

        if ($response->status() === 410) {
            return response()->json(['message' => 'Cet artifact a expirÃ© et n\'est plus disponible.'], 410);
        }

        return response()->json([
            'message' => 'Impossible de récupérer le lien de téléchargement.',
            'details' => $response->json('message'),
        ], $response->status());
    }

    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/workflows/runs/{runId}/release-assets
    // -------------------------------------------------------------------------

    /**
     * Retourne les assets de la release GitHub liée à un run CI.
     * Remplace l'affichage des artifacts CI (expirés après 90 jours).
     * Rôle requis : administrateur
     */
    public function releaseAssets(Request $request, string $id, string $runId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé. Réservé aux administrateurs.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        $token = $this->githubToken($request);
        if (! $token) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $http = $this->githubHttp($token);

        // 1. Récupérer les infos du run CI (commit SHA + branche)
        $runResponse = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}"
        );

        if ($runResponse->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
        }

        $runData  = $runResponse->json();
        $headSha  = $runData['head_sha'] ?? null;

        // 2. Lister les releases du dépôt (20 dernières)
        $releasesResponse = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/releases",
            ['per_page' => 20]
        );

        if (! $releasesResponse->successful()) {
            return response()->json([
                'message' => 'Impossible de récupérer les releases GitHub.',
                'details' => $releasesResponse->json('message'),
            ], $releasesResponse->status());
        }

        $releases       = $releasesResponse->json();
        $releaseChoisie = null;

        // 3. Trouver la release dont le tag pointe sur le même commit que le run
        foreach ($releases as $release) {
            if (! $headSha || ! isset($release['tag_name'])) {
                continue;
            }

            $tagResponse = $http->get(
                "https://api.github.com/repos/{$owner}/{$repo}/git/ref/tags/{$release['tag_name']}"
            );

            if (! $tagResponse->successful()) {
                continue;
            }

            $tagData   = $tagResponse->json();
            $tagCommit = $tagData['object']['sha'] ?? null;

            // Pour les tags annotés, résoudre le commit cible
            if ($tagCommit && ($tagData['object']['type'] ?? '') === 'tag') {
                $annotated = $http->get(
                    "https://api.github.com/repos/{$owner}/{$repo}/git/tags/{$tagCommit}"
                );
                if ($annotated->successful()) {
                    $tagCommit = $annotated->json()['object']['sha'] ?? $tagCommit;
                }
            }

            if ($tagCommit === $headSha) {
                $releaseChoisie = $release;
                break;
            }
        }

        // Si aucune correspondance exacte → prendre la release stable la plus récente
        if (! $releaseChoisie && ! empty($releases)) {
            $stables        = array_values(array_filter($releases, fn ($r) => ! $r['prerelease'] && ! $r['draft']));
            $releaseChoisie = ! empty($stables) ? $stables[0] : $releases[0];
        }

        if (! $releaseChoisie) {
            return response()->json([
                'run_id'  => $runId,
                'message' => 'Aucune release trouvée pour ce dépôt.',
                'release' => null,
                'total'   => 0,
                'assets'  => [],
            ]);
        }

        // 4. Formater les assets
        $assets = collect($releaseChoisie['assets'] ?? [])
            ->map(fn ($a) => [
                'id'           => $a['id'],
                'nom'          => $a['name'],
                'taille'       => $a['size'],
                'content_type' => $a['content_type'],
                'download_url' => $a['browser_download_url'],
                'created_at'   => $a['created_at'],
            ])
            ->values();

        return response()->json([
            'run_id'  => $runId,
            'release' => [
                'id'         => $releaseChoisie['id'],
                'tag'        => $releaseChoisie['tag_name'],
                'nom'        => $releaseChoisie['name'],
                'prerelease' => $releaseChoisie['prerelease'],
                'url_github' => $releaseChoisie['html_url'],
                'created_at' => $releaseChoisie['created_at'],
            ],
            'total'  => count($assets),
            'assets' => $assets,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/releases
    // -------------------------------------------------------------------------

    /**
     * Liste toutes les releases d'un dépôt GitHub.
     * Rôle requis : administrateur
     */
    public function releases(Request $request, string $id): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé. Réservé aux administrateurs.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        $token = $this->githubToken($request);
        if (! $token) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp($token)->get(
            "https://api.github.com/repos/{$owner}/{$repo}/releases",
            ['per_page' => 20]
        );

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'Impossible de récupérer les releases GitHub.',
                'details' => $response->json('message'),
            ], $response->status());
        }

        $releases = collect($response->json())->map(fn ($r) => [
            'id'         => $r['id'],
            'tag'        => $r['tag_name'],
            'nom'        => $r['name'],
            'prerelease' => $r['prerelease'],
            'draft'      => $r['draft'],
            'url_github' => $r['html_url'],
            'nb_assets'  => count($r['assets'] ?? []),
            'created_at' => $r['created_at'],
        ])->values();

        return response()->json([
            'total'    => count($releases),
            'releases' => $releases,
        ]);
    }

    /**
     * Crée un workflow dans le dépôt GitHub depuis un template YAML.
     * POST /api/projets/{id}/workflows/depuis-template
     */
    public function depuisTemplate(Request $request, string $id): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'a pas de dÃ©pÃ´t GitHub liÃ©.'], 422);
        }

        if (! $this->githubToken($request)) {
            return response()->json(['message' => 'GITHUB_TOKEN non configurÃ© dans .env.'], 500);
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

        $http = $this->githubHttp($this->githubToken($request));

        // VÃ©rifie si le fichier existe dÃ©jÃ  pour rÃ©cupÃ©rer son SHA
        $sha      = null;
        $existing = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}",
            ['ref' => $branch]
        );

        if ($existing->successful()) {
            $sha = $existing->json('sha');
        }

        $payload = [
            'message' => $sha ? "ci: update {$workflowName} workflow" : "ci: add {$workflowName} workflow",
            'content' => $content,
            'branch'  => $branch,
        ];

        if ($sha) {
            $payload['sha'] = $sha;
        }

        $response = $http->put(
            "https://api.github.com/repos/{$owner}/{$repo}/contents/{$path}",
            $payload
        );

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expirÃ©.'], 401);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'GitHub : ' . ($response->json('message') ?? 'Erreur inconnue.'),
                'details' => $response->json('errors'),
            ], $response->status());
        }

        $action = $sha ? 'mis Ã  jour' : 'crÃ©Ã©';

        return response()->json([
            'message'       => "Workflow \"{$workflowName}\" {$action} dans {$owner}/{$repo}.",
            'path'          => $path,
            'url_github'    => "https://github.com/{$owner}/{$repo}/blob/{$branch}/{$path}",
            'workflow_name' => $workflowName,
            'branch'        => $branch,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /api/projets/{id}/workflows/runs/{runId}/marquer-pret
    // -------------------------------------------------------------------------

    /**
     * L'administrateur marque un run CI rÃ©ussi comme "prÃªt Ã  dÃ©ployer".
     * CrÃ©e une entrÃ©e dans pipeline_pret et envoie une notification
     * Ã  tous les utilisateurs administrateur_cloud_doi de l'Ã©quipe du projet.
     *
     * RÃ´le requis : administrateur
     */
    public function marquerPret(Request $request, string $projetId, string $runId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $projetId);

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'AccÃ¨s refusÃ©. RÃ©servÃ© aux administrateurs.'], 403);
        }

        $request->validate([
            'branche'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'commit_sha'    => ['sometimes', 'nullable', 'string', 'max:40'],
            'nom_workflow'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Marquer tout ancien "prÃªt" non dÃ©ployÃ© comme dÃ©passÃ©
        PipelinePret::where('projet_id', $projet->id)
            ->where('deploye', false)
            ->update(['deploye' => true]);  // on le considÃ¨re obsolÃ¨te

        // CrÃ©er le nouveau marquage
        $pret = PipelinePret::create([
            'projet_id'    => $projet->id,
            'run_id'       => (int) $runId,
            'branche'      => $request->input('branche'),
            'commit_sha'   => $request->input('commit_sha'),
            'nom_workflow' => $request->input('nom_workflow'),
            'marque_par'   => $request->user()->id,
            'deploye'      => false,
        ]);

        // Notifier tous les Cloud DOI membres de l'Ã©quipe du projet
        $cloudDois = User::whereHas('equipes', function ($q) use ($projet) {
            $q->where('Equipes.id', $projet->equipe_id);
        })->where('role', 'administrateur_cloud_doi')->get();

        $nomProjet = $projet->nom;
        $branche   = $request->input('branche', 'inconnue');

        foreach ($cloudDois as $cloudDoi) {
            NotificationService::succes(
                $cloudDoi->id,
                "Pipeline CI rÃ©ussi â€” {$nomProjet}",
                "Le pipeline CI du projet \"{$nomProjet}\" (branche {$branche}) a rÃ©ussi. " .
                "L'application est prÃªte Ã  Ãªtre dÃ©ployÃ©e.",
            );
        }

        return response()->json([
            'message'       => "Run #{$runId} marquÃ© prÃªt. {$cloudDois->count()} Cloud DOI notifiÃ©(s).",
            'pipeline_pret' => $pret,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/pipeline-pret
    // -------------------------------------------------------------------------

    /**
     * Retourne le dernier run marquÃ© "prÃªt Ã  dÃ©ployer" et non encore dÃ©ployÃ©
     * pour un projet donnÃ©.
     * UtilisÃ© par le frontend du Cloud DOI pour afficher/masquer le bouton "DÃ©ployer".
     *
     * RÃ´le requis : tous les utilisateurs connectÃ©s ayant accÃ¨s au projet
     */
    public function pipelinePret(Request $request, string $projetId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $projetId);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'AccÃ¨s refusÃ©.'], 403);
        }

        $pret = PipelinePret::where('projet_id', $projet->id)
            ->where('deploye', false)
            ->with('marquePar:id,nom,prenom')
            ->latest()
            ->first();

        if (! $pret) {
            return response()->json(['pret' => false, 'pipeline' => null]);
        }

        return response()->json([
            'pret'     => true,
            'pipeline' => [
                'id'           => $pret->id,
                'run_id'       => $pret->run_id,
                'branche'      => $pret->branche,
                'commit_sha'   => $pret->commit_sha,
                'nom_workflow' => $pret->nom_workflow,
                'marque_par'   => $pret->marquePar
                    ? $pret->marquePar->nom . ' ' . $pret->marquePar->prenom
                    : null,
                'created_at'   => $pret->created_at,
            ],
        ]);
    }
}

