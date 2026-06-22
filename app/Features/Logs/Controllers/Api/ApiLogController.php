<?php

namespace App\Features\Logs\Controllers\Api;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Logs\Models\Log;
use App\Features\Logs\Models\LogDetail;
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
     * Retourne les lignes individuelles de logs d'un déploiement (table log_details).
     * Filtres optionnels : ?source=CI|CD  ?niveau=INFO|WARNING|ERROR  ?etape=BUILD
     */
    public function index(Request $request, int $deploiementId): JsonResponse
    {
        Deploiement::findOrFail($deploiementId);

        // Récupérer les IDs des entrées logs pour ce déploiement
        $logIds = Log::where('deploiement_id', $deploiementId)->pluck('id');

        $query = LogDetail::whereIn('log_id', $logIds)
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

        $details = $query->get();

        return response()->json([
            'deploiement_id' => $deploiementId,
            'total'          => $details->count(),
            'logs'           => $details,
        ]);
    }

    // =========================================================================
    // POST /api/deploiements/{id}/importer-logs-ci
    // =========================================================================

    /**
     * Importe les logs CI depuis GitHub Actions pour un déploiement donné.
     * Crée/met à jour l'entrée résumé dans logs et insère les lignes dans log_details.
     */
    public function importerLogsCI(Request $request, int $deploiementId): JsonResponse
    {
        $deploiement = Deploiement::with('projet')->findOrFail($deploiementId);

        if (! $deploiement->ci_run_id) {
            return response()->json(['message' => "Ce déploiement n'a pas de ci_run_id associé."], 422);
        }

        $projet = $deploiement->projet;

        if (! $projet || ! $projet->url_depot) {
            return response()->json(['message' => 'Projet ou dépôt GitHub introuvable.'], 422);
        }

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

        $http = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'Laravel-CICD-App'])
            ->timeout(60);

        if (env('HTTPS_PROXY')) {
            $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
        }

        $response = $http->withoutRedirecting()->get(
            "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$deploiement->ci_run_id}/logs"
        );

        $zipContenu = null;
        if ($response->status() === 302) {
            $zipResponse = Http::timeout(60)->get($response->header('Location'));
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
     * Reçoit un log CD depuis deploy.ps1 sur la VM.
     * Crée/met à jour l'entrée résumé dans logs et ajoute la ligne dans log_details.
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

        $deploiement = Deploiement::where('deployment_id', $validated['deployment_id'])
            ->orWhere('id', is_numeric($validated['deployment_id']) ? (int) $validated['deployment_id'] : 0)
            ->first();

        if (! $deploiement) {
            return response()->json(['ok' => false, 'message' => 'Déploiement introuvable.'], 404);
        }

        // Créer ou récupérer l'entrée résumé dans logs
        $logResume = DB::table('logs')
            ->where('deploiement_id', $deploiement->id)
            ->where('source', $validated['source'])
            ->first();

        if (! $logResume) {
            $logId = DB::table('logs')->insertGetId([
                'deploiement_id' => $deploiement->id,
                'source'         => $validated['source'],
                'niveau'         => 'INFO',
                'contenu_ci'     => null,
                'contenu_cd'     => null,
                'created_at'     => now(),
            ]);
        } else {
            $logId = $logResume->id;
        }

        // Insérer la ligne dans log_details
        DB::table('log_details')->insert([
            'log_id'     => $logId,
            'source'     => $validated['source'],
            'niveau'     => $validated['level'],
            'etape'      => isset($validated['step']) ? strtoupper($validated['step']) : null,
            'message'    => $validated['message'],
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true], 201);
    }

    // =========================================================================
    // GET /api/deploiements/{id}/logs-detail/download
    // =========================================================================

    /**
     * Télécharge tous les logs d'un déploiement sous forme de fichier texte.
     * Lit les lignes individuelles depuis log_details.
     */
    public function download(int $deploiementId): \Illuminate\Http\Response|JsonResponse
    {
        $deploiement = Deploiement::findOrFail($deploiementId);

        $logIds  = Log::where('deploiement_id', $deploiementId)->pluck('id');
        $details = LogDetail::whereIn('log_id', $logIds)->orderBy('created_at')->get();

        $lignes   = [];
        $lignes[] = "=== LOGS DÉPLOIEMENT #{$deploiementId} ===";
        $lignes[] = "Application : {$deploiement->nom_projet} v{$deploiement->version_projet}";
        $lignes[] = "Branche     : {$deploiement->branche}";
        $lignes[] = "Généré le   : " . now()->format('Y-m-d H:i:s');
        $lignes[] = str_repeat('=', 60);
        $lignes[] = '';

        $sourceEnCours = null;
        foreach ($details as $detail) {
            if ($detail->source !== $sourceEnCours) {
                $lignes[]      = '';
                $lignes[]      = str_repeat('-', 40);
                $lignes[]      = "[ {$detail->source} ]";
                $lignes[]      = str_repeat('-', 40);
                $sourceEnCours = $detail->source;
            }

            $ts     = $detail->created_at?->format('Y-m-d H:i:s') ?? '—';
            $etape  = $detail->etape ? "[{$detail->etape}] " : '';
            $lignes[] = "[{$ts}] [{$detail->niveau}] {$etape}{$detail->message}";
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
     * Parse un ZIP de logs GitHub Actions.
     * Crée/met à jour l'entrée résumé dans logs (contenu_ci) et insère les lignes dans log_details.
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

        // 1. Créer ou récupérer l'entrée résumé CI dans logs
        $logResume = DB::table('logs')
            ->where('deploiement_id', $deploiement->id)
            ->where('source', 'CI')
            ->first();

        if (! $logResume) {
            $logId = DB::table('logs')->insertGetId([
                'deploiement_id' => $deploiement->id,
                'source'         => 'CI',
                'niveau'         => 'INFO',
                'contenu_ci'     => null,
                'contenu_cd'     => null,
                'created_at'     => now(),
            ]);
        } else {
            $logId = $logResume->id;
            // Supprimer les anciennes lignes pour ré-import propre
            DB::table('log_details')->where('log_id', $logId)->delete();
        }

        $inserts      = [];
        $lignesTexte  = [];
        $niveauGlobal = 'INFO';
        $nbInseres    = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! str_ends_with(strtolower($stat['name']), '.txt')) {
                continue;
            }

            $texte = $zip->getFromIndex($i);
            if ($texte === false) {
                continue;
            }

            $etape         = strtoupper(preg_replace('/^\d+_/', '', basename($stat['name'], '.txt')));
            $lignesTexte[] = "=== {$etape} ===";

            foreach (explode("\n", $texte) as $ligne) {
                $ligne = rtrim($ligne);
                if ($ligne === '') {
                    continue;
                }

                $timestamp = now();
                $message   = $ligne;
                $niveau    = 'INFO';

                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z)\s(.+)$/', $ligne, $m)) {
                    try { $timestamp = \Carbon\Carbon::parse($m[1]); } catch (\Exception) {}
                    $message = $m[2];
                }

                $lower = strtolower($message);
                if (str_contains($lower, 'error') || str_contains($lower, 'failed') || str_contains($lower, 'failure')) {
                    $niveau = 'ERROR';
                    $niveauGlobal = 'ERROR';
                } elseif (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
                    $niveau = 'WARNING';
                    if ($niveauGlobal !== 'ERROR') $niveauGlobal = 'WARNING';
                }

                // 2. Lignes individuelles → log_details
                $inserts[] = [
                    'log_id'     => $logId,
                    'source'     => 'CI',
                    'niveau'     => $niveau,
                    'etape'      => $etape,
                    'message'    => mb_substr($message, 0, 65535),
                    'created_at' => $timestamp,
                ];

                $ts = $timestamp instanceof \Carbon\Carbon
                    ? $timestamp->format('Y-m-d H:i:s')
                    : now()->format('Y-m-d H:i:s');
                $lignesTexte[] = "{$ts} | [{$etape}] {$message}";

                if (count($inserts) >= 500) {
                    DB::table('log_details')->insert($inserts);
                    $nbInseres += count($inserts);
                    $inserts    = [];
                }
            }
        }

        $zip->close();
        unlink($tmpZip);

        if (! empty($inserts)) {
            DB::table('log_details')->insert($inserts);
            $nbInseres += count($inserts);
        }

        // 3. Mettre à jour le résumé dans logs
        if (! empty($lignesTexte)) {
            DB::table('logs')->where('id', $logId)->update([
                'contenu_ci' => implode("\n", $lignesTexte),
                'niveau'     => $niveauGlobal,
            ]);
        }

        return $nbInseres;
    }

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
