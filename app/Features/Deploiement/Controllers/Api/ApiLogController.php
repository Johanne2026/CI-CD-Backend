<?php

namespace App\Features\Deploiement\Controllers\Api;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Deploiement\Models\Log;
use App\Features\Projets\Models\Projet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class ApiLogController extends Controller
{
    // =========================================================================
    // GET /api/deploiements/{id}/logs-detail
    // =========================================================================

    /**
     * Retourne les logs structurés d'un déploiement depuis la table logs.
     * Filtres optionnels : ?source=CI|CD  ?niveau=INFO|WARNING|ERROR  ?etape=BUILD
     */
    public function index(Request $request, int $deploiementId): JsonResponse
    {
        $deploiement = Deploiement::findOrFail($deploiementId);

        $query = Log::where('deploiement_id', $deploiementId)
            ->orderBy('created_at');

        if ($request->filled('source')) {
            $query->where('source', strtoupper($request->source));
        }
        if ($request->filled('niveau')) {
            $query->where('niveau', strtoupper($request->niveau));
        }
        if ($request->filled('etape')) {
            $query->where('etape', strtoupper($request->etape));
        }

        $logs = $query->get();

        return response()->json([
            'deploiement_id' => $deploiementId,
            'total'          => $logs->count(),
            'logs'           => $logs,
        ]);
    }

    // =========================================================================
    // POST /api/deploiements/{id}/importer-logs-ci
    // =========================================================================

    /**
     * Importe les logs CI depuis GitHub Actions pour un déploiement donné.
     *
     * 1. Récupère le ci_run_id depuis le déploiement
     * 2. Appelle l'API GitHub pour télécharger le ZIP des logs
     * 3. Extrait et parse les fichiers .txt du ZIP
     * 4. Injecte les logs en BD dans la table logs
     *
     * Utilise deploy.meta.json pour enrichir les logs avec les étapes définies.
     */
    public function importerLogsCI(Request $request, int $deploiementId): JsonResponse
    {
        $deploiement = Deploiement::with('projet')->findOrFail($deploiementId);

        if (! $deploiement->ci_run_id) {
            return response()->json([
                'message' => 'Ce déploiement n\'a pas de ci_run_id associé.',
            ], 422);
        }

        $projet = $deploiement->projet;

        if (! $projet || ! $projet->url_depot) {
            return response()->json([
                'message' => 'Projet ou dépôt GitHub introuvable.',
            ], 422);
        }

        // Utiliser le token de l'admin créateur du projet, sinon GITHUB_TOKEN .env
        $admin = \App\Features\Auth\Models\User::find($projet->cree_par_id ?? 0);
        $token = ($admin && $admin->role === 'administrateur')
            ? $admin->tokenGithubEffectif()
            : config('services.github.token', '');

        if (! $token) {
            return response()->json(['message' => 'GITHUB_TOKEN non configuré.'], 500);
        }

        try {
            [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // ── Télécharger le ZIP des logs depuis GitHub ──────────────────────────
        $http = Http::withToken($token)
            ->withHeaders([
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Laravel-CICD-App',
            ])
            ->timeout(60);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        // GitHub retourne une redirection 302 vers le ZIP des logs
        $response = $http->withoutRedirecting()->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$deploiement->ci_run_id}/logs"
        );

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
            return response()->json([
                'message' => 'Impossible de télécharger les logs CI depuis GitHub.',
                'status'  => $response->status(),
            ], 502);
        }

        // ── Parser et injecter les logs ────────────────────────────────────────
        $nbInseres = $this->parserEtInsererLogsCI($zipContenu, $deploiement);

        return response()->json([
            'message'        => "{$nbInseres} ligne(s) de log CI importée(s) pour le déploiement #{$deploiementId}.",
            'deploiement_id' => $deploiementId,
            'nb_inseres'     => $nbInseres,
        ]);
    }

    // =========================================================================
    // POST /api/logs/cd   (sans authentification — appelé par deploy.ps1)
    // =========================================================================

    /**
     * Reçoit un log CD en temps réel depuis deploy.ps1 sur la VM.
     * Appelé par : Invoke-RestMethod -Uri ".../api/logs/cd" -Method POST
     *
     * Body JSON :
     * {
     *   "deployment_id": "deploy_27198809191",
     *   "source":        "CD",
     *   "level":         "INFO",
     *   "step":          "DEPLOY",
     *   "message":       "Installation terminée"
     * }
     */
    public function recevoirLogCD(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deployment_id' => ['required', 'string'],
            'source'        => ['required', 'in:CI,CD'],
            'level'         => ['required', 'in:INFO,WARNING,ERROR'],
            'step'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'message'       => ['required', 'string'],
        ]);

        // Trouver le déploiement par deployment_id (champ logique) ou par id
        $deploiement = Deploiement::where('deployment_id', $validated['deployment_id'])
            ->orWhere('id', is_numeric($validated['deployment_id'])
                ? (int) $validated['deployment_id']
                : 0)
            ->first();

        Log::create([
            'deploiement_id' => $deploiement?->id,
            'source'         => $validated['source'],
            'niveau'         => $validated['level'],
            'etape'          => isset($validated['step']) ? strtoupper($validated['step']) : null,
            'message'        => $validated['message'],
            'created_at'     => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    // =========================================================================
    // GET /api/deploiements/{id}/logs-detail/download
    // =========================================================================

    /**
     * Télécharge tous les logs d'un déploiement sous forme de fichier texte.
     */
    public function download(int $deploiementId): \Illuminate\Http\Response|JsonResponse
    {
        $deploiement = Deploiement::findOrFail($deploiementId);

        $logs = Log::where('deploiement_id', $deploiementId)
            ->orderBy('created_at')
            ->get();

        $lignes   = [];
        $lignes[] = "=== LOGS DÉPLOIEMENT #{$deploiementId} ===";
        $lignes[] = "Application : {$deploiement->nom_projet} v{$deploiement->version_projet}";
        $lignes[] = "Branche     : {$deploiement->branche}";
        $lignes[] = "Généré le   : " . now()->format('Y-m-d H:i:s');
        $lignes[] = str_repeat('=', 60);
        $lignes[] = '';

        $sourceEnCours = null;
        foreach ($logs as $log) {
            if ($log->source !== $sourceEnCours) {
                $lignes[]      = '';
                $lignes[]      = str_repeat('-', 40);
                $lignes[]      = "[ {$log->source} ]";
                $lignes[]      = str_repeat('-', 40);
                $sourceEnCours = $log->source;
            }

            $ts     = $log->created_at?->format('Y-m-d H:i:s') ?? '—';
            $etape  = $log->etape ? "[{$log->etape}] " : '';
            $lignes[] = "[{$ts}] [{$log->niveau}] {$etape}{$log->message}";
        }

        $contenu    = implode(PHP_EOL, $lignes);
        $nomFichier = "logs_deploiement_{$deploiementId}.log";

        return response($contenu, 200, [
            'Content-Type'        => 'text/plain; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$nomFichier}\"",
            'Content-Length'      => strlen($contenu),
        ]);
    }

    // =========================================================================
    // Méthodes privées
    // =========================================================================

    /**
     * Parse un ZIP de logs GitHub Actions et insère les logs en BD.
     * Le ZIP contient un .txt par job, nommé ex: "1_build.txt"
     *
     * Retourne le nombre de lignes insérées.
     */
    private function parserEtInsererLogsCI(string $zipContenu, Deploiement $deploiement): int
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'ci_logs_') . '.zip';
        file_put_contents($tmpZip, $zipContenu);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            return 0;
        }

        $nbInseres   = 0;
        $inserts     = [];
        $lignesTexte = []; // pour compiler logs_ci

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! str_ends_with(strtolower($stat['name']), '.txt')) {
                continue;
            }

            $texte = $zip->getFromIndex($i);
            if ($texte === false) {
                continue;
            }

            $nomFichier = basename($stat['name'], '.txt');
            $etape      = strtoupper(preg_replace('/^\d+_/', '', $nomFichier));

            $lignesTexte[] = "=== {$etape} ===";

            foreach (explode("\n", $texte) as $ligne) {
                $ligne = rtrim($ligne);
                if ($ligne === '') {
                    continue;
                }

                $niveau    = $this->detecterNiveau($ligne);
                $timestamp = now();
                $message   = $ligne;

                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z)\s(.+)$/', $ligne, $m)) {
                    try {
                        $timestamp = \Carbon\Carbon::parse($m[1]);
                    } catch (\Exception) {
                    }
                    $message = $m[2];
                }

                $inserts[] = [
                    'deploiement_id' => $deploiement->id,
                    'source'         => 'CI',
                    'niveau'         => $niveau,
                    'etape'          => $etape,
                    'message'        => mb_substr($message, 0, 65535),
                    'created_at'     => $timestamp,
                ];

                $ts = $timestamp instanceof \Carbon\Carbon
                    ? $timestamp->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');
                $lignesTexte[] = "{$ts} | [{$etape}] {$message}";

                if (count($inserts) >= 500) {
                    DB::table('logs')->insert($inserts);
                    $nbInseres += count($inserts);
                    $inserts    = [];
                }
            }
        }

        $zip->close();
        unlink($tmpZip);

        if (! empty($inserts)) {
            DB::table('logs')->insert($inserts);
            $nbInseres += count($inserts);
        }

        // Sauvegarder le texte compilé dans la table logs (colonne contenu_ci)
        if (! empty($lignesTexte)) {
            DB::table('logs')->insert([
                'deploiement_id' => $deploiement->id,
                'source'         => 'CI',
                'niveau'         => 'INFO',
                'etape'          => 'COMPILE',
                'message'        => '[Logs CI compilés]',
                'contenu_ci'     => implode("\n", $lignesTexte),
                'contenu_cd'     => null,
                'created_at'     => now(),
            ]);
        }

        return $nbInseres;
    }

    /**
     * Détecte le niveau de log depuis le contenu d'une ligne.
     */
    private function detecterNiveau(string $ligne): string
    {
        $lower = strtolower($ligne);

        if (str_contains($lower, 'error') || str_contains($lower, 'failed') || str_contains($lower, 'failure')) {
            return 'ERROR';
        }

        if (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
            return 'WARNING';
        }

        return 'INFO';
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
}
