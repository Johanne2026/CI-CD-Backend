<?php

namespace App\Console\Commands;

use App\Features\Deploiement\Models\Deploiement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ZipArchive;

class RemplirLogsCi extends Command
{
    protected $signature = 'deploiements:remplir-logs-ci
                            {--limit=0 : Limiter le nombre de déploiements traités (0 = tous)}
                            {--dry-run : Afficher ce qui serait fait sans modifier la BD}';

    protected $description = 'Remplit logs_ci pour tous les déploiements qui ont un ci_run_id mais pas encore de logs_ci';

    public function handle(): int
    {
        $limit  = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $token = config('services.github.token', '');
        if (! $token) {
            $this->error('GITHUB_TOKEN non configuré dans .env — impossible d\'importer les logs CI.');
            return self::FAILURE;
        }

        // Récupérer tous les déploiements sans logs CI compilés dans la table logs
        // (ceux qui n'ont pas encore d'entrée source=CI, etape=COMPILE dans logs)
        $deploiementsAvecCompile = DB::table('logs')
            ->where('source', 'CI')
            ->where('etape', 'COMPILE')
            ->pluck('deploiement_id')
            ->toArray();

        $query = Deploiement::with('projet')
            ->whereNotNull('ci_run_id')
            ->whereNotIn('id', $deploiementsAvecCompile)
            ->whereHas('projet', fn ($q) => $q->whereNotNull('url_depot'))
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $deploiements = $query->get();
        $total        = $deploiements->count();

        if ($total === 0) {
            $this->info('✅ Tous les déploiements ont déjà leurs logs CI compilés.');
            return self::SUCCESS;
        }

        $this->info("🔍 {$total} déploiement(s) sans logs CI compilés trouvé(s)." . ($dryRun ? ' [DRY-RUN]' : ''));
        $this->newLine();

        $bar     = $this->output->createProgressBar($total);
        $succes  = 0;
        $echecs  = 0;
        $vides   = 0;

        foreach ($deploiements as $deploiement) {
            $projet = $deploiement->projet;

            if (! $projet || ! $projet->url_depot) {
                $bar->advance();
                $echecs++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [DRY] ID={$deploiement->id} ci_run_id={$deploiement->ci_run_id} projet={$projet->nom}");
                $bar->advance();
                $succes++;
                continue;
            }

            try {
                [$owner, $repo] = $this->parseDepotUrl($projet->url_depot);
            } catch (\Exception $e) {
                $echecs++;
                $bar->advance();
                continue;
            }

            // Télécharger le ZIP des logs depuis GitHub
            $http = Http::withToken($token)
                ->withHeaders([
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Laravel-CICD-App',
                ])
                ->timeout(60);

            if (env('HTTPS_PROXY')) {
                $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
            }

            try {
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
                    // Logs expirés ou inaccessibles — insérer une entrée indicative dans la table logs
                    DB::table('logs')->insert([
                        'deploiement_id' => $deploiement->id,
                        'source'         => 'CI',
                        'niveau'         => 'WARNING',
                        'etape'          => 'COMPILE',
                        'message'        => '[Logs CI compilés]',
                        'contenu_ci'     => "[Logs CI non disponibles — run #{$deploiement->ci_run_id}]",
                        'contenu_cd'     => null,
                        'created_at'     => now(),
                    ]);
                    $vides++;
                    $bar->advance();
                    continue;
                }

                $nbInseres = $this->parserEtSauvegarder($zipContenu, $deploiement);
                $succes++;

            } catch (\Exception $e) {
                $echecs++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Résultat', 'Nombre'],
            [
                ['✅ Importés avec succès', $succes],
                ['⚠️  Logs non disponibles (expirés)', $vides],
                ['❌ Erreurs', $echecs],
                ['Total traités', $total],
            ]
        );

        return self::SUCCESS;
    }

    private function parserEtSauvegarder(string $zipContenu, Deploiement $deploiement): int
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
                } elseif (str_contains($lower, 'warning')) {
                    $niveau = 'WARNING';
                    if ($niveauGlobal !== 'ERROR') $niveauGlobal = 'WARNING';
                }

                $inserts[] = [
                    'log_id'     => $logId,
                    'source'     => 'CI',
                    'niveau'     => $niveau,
                    'etape'      => $etape,
                    'message'    => mb_substr($message, 0, 65535),
                    'created_at' => $timestamp,
                ];

                $ts            = $timestamp instanceof \Carbon\Carbon
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

        // 3. Mettre à jour le résumé dans logs (contenu_ci + niveau global)
        if (! empty($lignesTexte)) {
            DB::table('logs')->where('id', $logId)->update([
                'contenu_ci' => implode("\n", $lignesTexte),
                'niveau'     => $niveauGlobal,
            ]);
        }

        return $nbInseres;
    }

    private function parseDepotUrl(string $url): array
    {
        $url   = rtrim($url, '/');
        $url   = preg_replace('/\.git$/', '', $url);
        $parts = array_values(array_filter(explode('/', parse_url($url, PHP_URL_PATH))));

        if (count($parts) < 2) {
            throw new \InvalidArgumentException('URL GitHub invalide.');
        }

        return [$parts[count($parts) - 2], $parts[count($parts) - 1]];
    }
}
