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
        $http = Http::withHeaders(['Accept' => 'application/vnd.github+json']);
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

        $http  = Http::withHeaders(['Accept' => 'application/vnd.github+json']);
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
}
