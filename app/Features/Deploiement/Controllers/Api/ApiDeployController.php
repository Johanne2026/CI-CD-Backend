<?php

namespace App\Features\Deploiement\Controllers\Api;

use App\Features\Deploiement\Models\Deploiement;
use App\Features\Projets\Models\Projet;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class ApiDeployController extends Controller
{
    /** URL de base de l'API REST de la VM. Exemple : http://192.168.1.50:5000 */
    private string $vmUrl;

    public function __construct()
    {
        $this->vmUrl = config('deploy.vm_url', '');
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 1 — POST /api/projets/{id}/upload-zip
    // -------------------------------------------------------------------------

    /**
     * Reçoit le .zip, lit deploy.meta.json, envoie à la VM et retourne le deploiement_id.
     * Rôle requis : administrateur_cloud_doi
     * Body : multipart/form-data — champ "zip" (.zip, max 500 Mo)
     */
    public function uploadZip(Request $request, int $id): JsonResponse
    {
        set_time_limit(1800); // 30 minutes

        $projet = Projet::findOrFail($id);

        if ($request->user()->role !== 'administrateur_cloud_doi') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->actif) {
            return response()->json(['message' => 'Ce projet est archivé.'], 422);
        }

        $request->validate([
            'zip' => ['required', 'file', 'mimes:zip', 'max:512000'],
        ]);

        $fichierZip = $request->file('zip');
        $zipPath    = $fichierZip->getRealPath();

        // Lire deploy.meta.json dans le .zip
        $meta = $this->lireMetaDepuisZip($zipPath);

        if ($meta === null) {
            return response()->json([
                'message' => 'Le fichier .zip ne contient pas de deploy.meta.json valide.',
            ], 422);
        }

        $app     = $meta['application']['name']    ?? $meta['app']     ?? null;
        $version = $meta['application']['version'] ?? $meta['version'] ?? null;

        if (! $app || ! $version) {
            return response()->json([
                'message' => 'deploy.meta.json doit contenir application.name et application.version.',
            ], 422);
        }

        if ($this->vmUrl === '') {
            return response()->json(['message' => 'DEPLOY_VM_URL non configuré dans .env.'], 500);
        }

        // Envoyer le .zip à la VM en body brut (application/octet-stream)
        $reponseUpload = Http::timeout(1800)
            ->withHeaders([
                'X-App-Name'   => $app,
                'Content-Type' => 'application/octet-stream',
            ])
            ->withBody(fopen($zipPath, 'r'), 'application/octet-stream')
            ->post(rtrim($this->vmUrl, '/') . '/upload');

        if (! $reponseUpload->successful()) {
            return response()->json([
                'message' => "Échec de l'upload vers la VM ({$reponseUpload->status()}) : {$reponseUpload->body()}",
            ], 502);
        }

        // Enregistrer en base (statut = en_attente)
        $deploiement = Deploiement::create([
            'projet_id' => $projet->id,
            'lance_par' => $request->user()->id,
            'app'       => $app,
            'version'   => $version,
            'statut'    => 'en_attente',
        ]);

        return response()->json([
            'message'        => "✅ Upload de \"{$app}\" réussi. Prêt à déployer.",
            'deploiement_id' => $deploiement->id,
            'app'            => $app,
            'version'        => $version,
        ]);
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 2 — POST /api/deploiements/{id}/lancer
    // -------------------------------------------------------------------------

    /**
     * Appelle /deploy sur la VM de façon SYNCHRONE — attend la fin de deploy.ps1.
     * La VM répond quand deploy.ps1 est terminé avec { statut, logs }.
     * Laravel met à jour la BD et retourne le résultat final au frontend.
     * Pas de polling nécessaire.
     *
     * Rôle requis : administrateur_cloud_doi
     */
    public function lancerDeploi(Request $request, int $id): JsonResponse
    {
        set_time_limit(1800); // 30 minutes

        $deploiement = Deploiement::findOrFail($id);

        if ($request->user()->role !== 'administrateur_cloud_doi') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($deploiement->statut !== 'en_attente') {
            return response()->json([
                'message' => "Ce déploiement est déjà en statut \"{$deploiement->statut}\".",
            ], 422);
        }

        if ($this->vmUrl === '') {
            $deploiement->update(['statut' => 'echoue', 'logs' => 'DEPLOY_VM_URL non configuré.']);
            return response()->json(['message' => 'DEPLOY_VM_URL non configuré dans .env.'], 500);
        }

        $deploiement->update(['statut' => 'en_cours']);

        try {
            // Timeout 30 min — attend que deploy.ps1 termine complètement
            $reponseVm = Http::timeout(1800)->post(rtrim($this->vmUrl, '/') . '/deploy', [
                'app'  => $deploiement->app,
                'user' => $request->user()->nom . ' ' . $request->user()->prenom,
            ]);

            Log::info('VM /deploy réponse', [
                'deploiement_id' => $deploiement->id,
                'status'         => $reponseVm->status(),
                'body'           => substr($reponseVm->body(), 0, 500),
            ]);

            $data   = $reponseVm->json();
            $statut = $data['statut'] ?? ($reponseVm->successful() ? 'termine' : 'echoue');
            $logs   = $data['logs']   ?? $reponseVm->body();

            $deploiement->update(['statut' => $statut, 'logs' => $logs]);

            $message = $statut === 'termine'
                ? "✅ Déploiement de \"{$deploiement->app}\" terminé avec succès."
                : "❌ Déploiement de \"{$deploiement->app}\" échoué.";

            return response()->json([
                'message'        => $message,
                'deploiement_id' => $deploiement->id,
                'statut'         => $statut,
                'logs'           => $logs,
            ]);

        } catch (\Exception $e) {
            $deploiement->update([
                'statut' => 'echoue',
                'logs'   => "Connexion à la VM impossible : {$e->getMessage()}",
            ]);

            return response()->json([
                'message'        => "Impossible de joindre la VM : {$e->getMessage()}",
                'deploiement_id' => $deploiement->id,
                'statut'         => 'echoue',
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/deploiements/{id}/logs
    // -------------------------------------------------------------------------

    /**
     * Lecture directe du statut et des logs en base.
     * Utile pour revoir un déploiement passé.
     */
    public function getLogs(int $id): JsonResponse
    {
        $deploiement = Deploiement::findOrFail($id);

        return response()->json([
            'deploiement_id' => $deploiement->id,
            'statut'         => $deploiement->statut,
            'logs'           => $deploiement->logs ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Lit deploy.meta.json dans un .zip sans extraire les fichiers.
     * Cherche à la racine, dans un sous-dossier de premier niveau, ou partout.
     */
    private function lireMetaDepuisZip(string $zipPath): ?array
    {
        $zip = new ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $contenu = $zip->getFromName('deploy.meta.json');

        if ($contenu === false) {
            $nomSansZip = pathinfo($zipPath, PATHINFO_FILENAME);
            $contenu    = $zip->getFromName($nomSansZip . '/deploy.meta.json');
        }

        if ($contenu === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat && basename($stat['name']) === 'deploy.meta.json') {
                    $contenu = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if (! $contenu) {
            return null;
        }

        $meta = json_decode($contenu, true);

        return is_array($meta) ? $meta : null;
    }
}
