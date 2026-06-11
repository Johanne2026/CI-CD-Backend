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
     * Retourne le token GitHub depuis la config (.env GITHUB_TOKEN).
     * Toutes les méthodes utilisent ce token centralisé.
     */
    private function githubToken(): string
    {
        return config('services.github.token', '');
    }

    /**
     * Construit un client Http:: préconfiguré pour l'API GitHub.
     */
    private function githubHttp(): \Illuminate\Http\Client\PendingRequest
    {
        $http = Http::withHeaders([
            'Accept'            => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent'        => 'Laravel-CICD-App',
            'Accept-Encoding'   => 'gzip, deflate',  // réduit la taille de la réponse
        ])->timeout(60);  // 60s — GitHub peut être lent selon le réseau

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        $token = $this->githubToken();
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
     * Synchronise et retourne tous les workflows du dépôt GitHub lié.
     * POST /api/projets/{id}/workflows/sync
     */
    public function sync(Request $request, string $id): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp()
            ->get("https://api.github.com/repos/{$owner}/{$repo}/actions/workflows");

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
        }

        if ($response->status() === 404) {
            return response()->json([
                'message' => "Dépôt introuvable : {$owner}/{$repo}.",
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
     * Retourne les exécutions d'un workflow spécifique.
     * GET /api/projets/{id}/workflows/{workflowId}/runs
     */
    public function runs(Request $request, string $id, string $workflowId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp()->get(
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

        $response = $this->githubHttp()->get(
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

        $token = $this->githubToken();
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
            return response()->json(['message' => 'Impossible de récupérer le contenu du template.'], $response->status());
        }

        return response()->json([
            'fichier'      => $fichier,
            'yaml_content' => $response->body(),
        ]);
    }

    /**
     * Retourne les artifacts d'une exécution spécifique.
     * GET /api/projets/{id}/workflows/runs/{runId}/artifacts
     */
    public function artifacts(Request $request, string $id, string $runId): JsonResponse
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

        if (! $this->githubToken()) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $response = $this->githubHttp()->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/artifacts"
        );

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
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
     * Génère une URL de téléchargement signée pour un artifact.
     * GET /api/projets/{id}/workflows/artifacts/{artifactId}/download
     */
    public function downloadArtifact(Request $request, string $id, string $artifactId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé. Réservé aux administrateurs.'], 403);
        }

        if (! $this->githubToken()) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré dans .env.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $http = $this->githubHttp()->withoutRedirecting();

        $response = $http->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/artifacts/{$artifactId}/zip"
        );

        if ($response->status() === 302) {
            return response()->json(['download_url' => $response->header('Location')]);
        }

        if ($response->status() === 401) {
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
        }

        if ($response->status() === 410) {
            return response()->json(['message' => 'Cet artifact a expiré et n\'est plus disponible.'], 410);
        }

        return response()->json([
            'message' => 'Impossible de récupérer le lien de téléchargement.',
            'details' => $response->json('message'),
        ], $response->status());
    }

    /**
     * Crée un workflow dans le dépôt GitHub depuis un template YAML.
     * POST /api/projets/{id}/workflows/depuis-template
     */
    public function depuisTemplate(Request $request, string $id): JsonResponse
    {
        $projet = Projet::findOrFail((int) $id);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'a pas de dépôt GitHub lié.'], 422);
        }

        if (! $this->githubToken()) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré dans .env.'], 500);
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

        $http = $this->githubHttp();

        // Vérifie si le fichier existe déjà pour récupérer son SHA
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
            return response()->json(['message' => 'Token GitHub invalide ou expiré.'], 401);
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

    // -------------------------------------------------------------------------
    // POST /api/projets/{id}/workflows/runs/{runId}/marquer-pret
    // -------------------------------------------------------------------------

    /**
     * L'administrateur marque un run CI réussi comme "prêt à déployer".
     * Crée une entrée dans pipeline_pret et envoie une notification
     * à tous les utilisateurs administrateur_cloud_doi de l'équipe du projet.
     *
     * Rôle requis : administrateur
     */
    public function marquerPret(Request $request, string $projetId, string $runId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $projetId);

        if ($request->user()->role !== 'administrateur') {
            return response()->json(['message' => 'Accès refusé. Réservé aux administrateurs.'], 403);
        }

        $request->validate([
            'branche'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'commit_sha'    => ['sometimes', 'nullable', 'string', 'max:40'],
            'nom_workflow'  => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Marquer tout ancien "prêt" non déployé comme dépassé
        PipelinePret::where('projet_id', $projet->id)
            ->where('deploye', false)
            ->update(['deploye' => true]);  // on le considère obsolète

        // Créer le nouveau marquage
        $pret = PipelinePret::create([
            'projet_id'    => $projet->id,
            'run_id'       => (int) $runId,
            'branche'      => $request->input('branche'),
            'commit_sha'   => $request->input('commit_sha'),
            'nom_workflow' => $request->input('nom_workflow'),
            'marque_par'   => $request->user()->id,
            'deploye'      => false,
        ]);

        // Notifier tous les Cloud DOI membres de l'équipe du projet
        $cloudDois = User::whereHas('equipes', function ($q) use ($projet) {
            $q->where('Equipes.id', $projet->equipe_id);
        })->where('role', 'administrateur_cloud_doi')->get();

        $nomProjet = $projet->nom;
        $branche   = $request->input('branche', 'inconnue');

        foreach ($cloudDois as $cloudDoi) {
            NotificationService::succes(
                $cloudDoi->id,
                "Pipeline CI réussi — {$nomProjet}",
                "Le pipeline CI du projet \"{$nomProjet}\" (branche {$branche}) a réussi. " .
                "L'application est prête à être déployée.",
            );
        }

        return response()->json([
            'message'       => "Run #{$runId} marqué prêt. {$cloudDois->count()} Cloud DOI notifié(s).",
            'pipeline_pret' => $pret,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/pipeline-pret
    // -------------------------------------------------------------------------

    /**
     * Retourne le dernier run marqué "prêt à déployer" et non encore déployé
     * pour un projet donné.
     * Utilisé par le frontend du Cloud DOI pour afficher/masquer le bouton "Déployer".
     *
     * Rôle requis : tous les utilisateurs connectés ayant accès au projet
     */
    public function pipelinePret(Request $request, string $projetId): JsonResponse
    {
        $projet = Projet::findOrFail((int) $projetId);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
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
