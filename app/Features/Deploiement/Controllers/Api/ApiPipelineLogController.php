<?php

namespace App\Features\Deploiement\Controllers\Api;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Equipes\Models\MembreEquipe;
use App\Features\Projets\Models\Projet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ApiPipelineLogController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/pipelines/{runId}/logs
    // -------------------------------------------------------------------------

    /**
     * Retourne les logs CI (GitHub Actions) et CD (VM) fusionnés et structurés
     * pour un run GitHub Actions donné.
     *
     * Structure de réponse :
     * {
     *   "run_id": 123456,
     *   "sections": [
     *     { "source": "CI", "job": "build", "lignes": [...] },
     *     { "source": "CD", "job": "deploy", "lignes": [...] }
     *   ],
     *   "cd_statut": "termine" | "echoue" | null,
     *   "cd_deploiement_id": 42 | null
     * }
     */
    public function logs(Request $request, int $projetId, int $runId): JsonResponse
    {
        $projet = Projet::findOrFail($projetId);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        $sections = [];

        // ── 1. Logs CI depuis GitHub ───────────────────────────────────────────
        $sectionsCi = $this->recupererLogsCI($projet, $runId);
        $sections   = array_merge($sections, $sectionsCi);

        // ── 2. Logs CD depuis la BD (deploiements liés au run) ─────────────────
        $deploiement = Deploiement::where('projet_id', $projetId)
            ->where('github_run_id', $runId)
            ->latest()
            ->first();

        $cdStatut       = null;
        $cdDeploiementId = null;

        if ($deploiement) {
            $cdStatut        = $deploiement->statut;
            $cdDeploiementId = $deploiement->id;
            $sections[]      = $this->formaterSectionCD($deploiement);
        }

        return response()->json([
            'run_id'             => $runId,
            'sections'           => $sections,
            'cd_statut'          => $cdStatut,
            'cd_deploiement_id'  => $cdDeploiementId,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/projets/{id}/pipelines/{runId}/logs/download
    // -------------------------------------------------------------------------

    /**
     * Télécharge un fichier .log unifié CI+CD pour un run donné.
     * Nom du fichier : pipeline_{runId}.log
     */
    public function download(Request $request, int $projetId, int $runId): Response|JsonResponse
    {
        $projet = Projet::findOrFail($projetId);

        if (! $this->verifierAcces($request, $projet)) {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->url_depot) {
            return response()->json(['message' => 'Ce projet n\'est pas lié à un dépôt GitHub.'], 422);
        }

        $contenu = [];
        $contenu[] = "=== PIPELINE LOG — Run #{$runId} ===";
        $contenu[] = "Projet   : {$projet->nom}";
        $contenu[] = "Généré   : " . now()->format('Y-m-d H:i:s');
        $contenu[] = str_repeat('=', 60);
        $contenu[] = '';

        // ── Logs CI ────────────────────────────────────────────────────────────
        $contenu[] = str_repeat('-', 60);
        $contenu[] = '[ CI — GitHub Actions ]';
        $contenu[] = str_repeat('-', 60);

        $sectionsCi = $this->recupererLogsCI($projet, $runId);

        if (empty($sectionsCi)) {
            $contenu[] = '(Aucun log CI disponible)';
        } else {
            foreach ($sectionsCi as $section) {
                $contenu[] = '';
                $contenu[] = "## Job : {$section['job']}";
                foreach ($section['lignes'] as $ligne) {
                    $contenu[] = "[{$ligne['timestamp']}] {$ligne['texte']}";
                }
            }
        }

        $contenu[] = '';

        // ── Logs CD ────────────────────────────────────────────────────────────
        $contenu[] = str_repeat('-', 60);
        $contenu[] = '[ CD — Déploiement VM ]';
        $contenu[] = str_repeat('-', 60);

        $deploiement = Deploiement::where('projet_id', $projetId)
            ->where('github_run_id', $runId)
            ->latest()
            ->first();

        if (! $deploiement) {
            $contenu[] = '(Aucun déploiement CD lié à ce run)';
        } else {
            $contenu[] = "Statut        : {$deploiement->statut}";
            $contenu[] = "Application   : {$deploiement->app} v{$deploiement->version}";
            $contenu[] = "Déploiement # : {$deploiement->id}";
            $contenu[] = '';
            $contenu[] = '## Logs deploy.ps1';
            $contenu[] = $deploiement->logs ?? '(aucun log)';
        }

        $texte    = implode(PHP_EOL, $contenu);
        $nomFichier = "pipeline_{$runId}.log";

        return response($texte, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
            'Content-Length'      => strlen($texte),
        ]);
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Récupère et structure les logs CI depuis GitHub Actions.
     * GitHub retourne un ZIP contenant un .txt par job.
     * Retourne un tableau de sections structurées.
     */
    private function recupererLogsCI(Projet $projet, int $runId): array
    {
        $token = config('services.github.token', '');
        if (! $token || ! $projet->url_depot) {
            return [];
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\Exception $e) {
            return [];
        }

        $http = Http::withToken($token)
            ->withHeaders([
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Laravel-CICD-App',
            ])
            ->timeout(60);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        // GitHub retourne un ZIP avec les logs
        $response = $http->withoutRedirecting()->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$runId}/logs"
        );

        // Suivre la redirection manuellement si nécessaire
        $zipContenu = null;
        if ($response->status() === 302) {
            $redirectUrl = $response->header('Location');
            $zipResponse = Http::timeout(60)->get($redirectUrl);
            if ($zipResponse->successful()) {
                $zipContenu = $zipResponse->body();
            }
        } elseif ($response->successful()) {
            $zipContenu = $response->body();
        }

        if (! $zipContenu) {
            return [];
        }

        return $this->extraireLogsDepuisZip($zipContenu);
    }

    /**
     * Extrait et structure les logs depuis le ZIP retourné par GitHub.
     * Chaque fichier .txt dans le ZIP correspond à un job.
     */
    private function extraireLogsDepuisZip(string $zipContenu): array
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'github_logs_') . '.zip';
        file_put_contents($tmpZip, $zipContenu);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            return [];
        }

        $sections = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! str_ends_with($stat['name'], '.txt')) {
                continue;
            }

            $texte = $zip->getFromIndex($i);
            if ($texte === false) {
                continue;
            }

            // Nom du job = nom du fichier sans extension et sans numéro de préfixe
            // Ex: "1_build.txt" → "build"
            $nomFichier = basename($stat['name'], '.txt');
            $nomJob     = preg_replace('/^\d+_/', '', $nomFichier);

            $sections[] = [
                'source' => 'CI',
                'job'    => $nomJob,
                'lignes' => $this->parserLignesLog($texte),
            ];
        }

        $zip->close();
        unlink($tmpZip);

        return $sections;
    }

    /**
     * Parse les lignes d'un log GitHub Actions.
     * Format GitHub : "2026-06-10T10:00:00.000Z texte de la ligne"
     */
    private function parserLignesLog(string $texte): array
    {
        $lignes   = explode("\n", trim($texte));
        $resultat = [];

        foreach ($lignes as $ligne) {
            $ligne = rtrim($ligne);
            if ($ligne === '') {
                continue;
            }

            // Extraire le timestamp ISO 8601 en début de ligne
            if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z)\s(.*)$/', $ligne, $m)) {
                $resultat[] = [
                    'timestamp' => $m[1],
                    'texte'     => $m[2],
                ];
            } else {
                $resultat[] = [
                    'timestamp' => null,
                    'texte'     => $ligne,
                ];
            }
        }

        return $resultat;
    }

    /**
     * Formate les logs CD d'un déploiement en section structurée.
     */
    private function formaterSectionCD(Deploiement $deploiement): array
    {
        $logsTexte = $deploiement->logs ?? '';
        $lignes    = [];

        foreach (explode("\n", trim($logsTexte)) as $ligne) {
            $ligne = rtrim($ligne);
            if ($ligne === '') {
                continue;
            }

            // Tenter de parser le format de log PowerShell : "2026-06-10 10:00:00 | message"
            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s*\|\s*(.*)$/', $ligne, $m)) {
                $lignes[] = [
                    'timestamp' => $m[1],
                    'texte'     => $m[2],
                ];
            } else {
                $lignes[] = [
                    'timestamp' => null,
                    'texte'     => $ligne,
                ];
            }
        }

        return [
            'source' => 'CD',
            'job'    => "deploy — {$deploiement->app} v{$deploiement->version} ({$deploiement->statut})",
            'lignes' => $lignes,
        ];
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
}
