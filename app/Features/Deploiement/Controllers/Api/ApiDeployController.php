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
     * Reçoit le .zip, lit deploy.meta.json, envoie à la VM et crée l'entrée en BD.
     *
     * Rôle requis : administrateur_cloud_doi
     *
     * Body multipart/form-data :
     *   zip           : fichier .zip (obligatoire)
     *   ci_run_id     : ID du run GitHub Actions CI (optionnel)
     *   commit_hash   : SHA du commit déployé (optionnel)
     *   branche       : branche déployée (optionnel)
     *   package_hash  : hash de l'artifact CI (optionnel)
     *   nom_package   : nom du package artifact (optionnel)
     *   declenche_par : ID de l'utilisateur qui a déclenché le CI (optionnel)
     */
    public function uploadZip(Request $request, int $id): JsonResponse
    {
        set_time_limit(1800);

        $projet = Projet::findOrFail($id);

        if ($request->user()->role !== 'administrateur_cloud_doi') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if (! $projet->actif) {
            return response()->json(['message' => 'Ce projet est archivé.'], 422);
        }

        $request->validate([
            'zip'           => ['required', 'file', 'mimes:zip', 'max:512000'],
            'ci_run_id'     => ['sometimes', 'nullable', 'integer'],
            'commit_hash'   => ['sometimes', 'nullable', 'string', 'max:40'],
            'branche'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'package_hash'  => ['sometimes', 'nullable', 'string', 'max:64'],
            'nom_package'   => ['sometimes', 'nullable', 'string', 'max:255'],
            'declenche_par' => ['sometimes', 'nullable', 'integer', 'exists:Utilisateurs,id'],
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

        // Extraire les champs depuis deploy.meta.json
        $app          = $meta['application']['name']    ?? $meta['app']     ?? null;
        $version      = $meta['application']['version'] ?? $meta['version'] ?? null;
        $deploymentId = $meta['deployment_id']          ?? null;
        $environnement = $meta['deployment']['environment']
                      ?? $meta['environment']
                      ?? 'PPR';

        if (! $app || ! $version) {
            return response()->json([
                'message' => 'deploy.meta.json doit contenir application.name et application.version.',
            ], 422);
        }

        if ($this->vmUrl === '') {
            return response()->json(['message' => 'DEPLOY_VM_URL non configuré dans .env.'], 500);
        }

        // Envoyer le .zip à la VM
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

        // Créer l'entrée en base avec tous les champs disponibles
        $deploiement = Deploiement::create([
            // Identifiants
            'deployment_id'           => $deploymentId ?? "deploy_{$request->input('ci_run_id', uniqid())}",
            'ci_run_id'               => $request->input('ci_run_id'),
            'projet_id'               => $projet->id,

            // Infos projet
            'nom_projet'              => $projet->nom,
            'version_projet'          => $version,
            'commit_hash'             => $request->input('commit_hash'),
            'branche'                 => $request->input('branche'),
            'environnement'           => $environnement,

            // Statuts initiaux
            'final_statut'            => 'EN_ATTENTE',
            'ci_statut'               => 'SUCCES',    // l'upload implique que le CI a réussi
            'cd_statut'               => 'EN_ATTENTE',

            // Package
            'package_hash'            => $request->input('package_hash'),
            'nom_package'             => $request->input('nom_package') ?? $fichierZip->getClientOriginalName(),

            // Acteurs
            'declenche_par'           => $request->input('declenche_par'),
            'deploye_sur_serveur_par' => $request->user()->id,

            // Compatibilité
            'app'                     => $app,
            'version'                 => $version,

            // Timing — commence quand le CD démarre, pas maintenant
            'commence_a'              => now(),
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
     * Appelle /deploy sur la VM de façon synchrone.
     * Met à jour tous les champs de suivi à la fin.
     *
     * Rôle requis : administrateur_cloud_doi
     */
    public function lancerDeploi(Request $request, int $id): JsonResponse
    {
        set_time_limit(1800);

        $deploiement = Deploiement::findOrFail($id);

        if ($request->user()->role !== 'administrateur_cloud_doi') {
            return response()->json(['message' => 'Accès refusé.'], 403);
        }

        if ($deploiement->final_statut !== 'EN_ATTENTE') {
            return response()->json([
                'message' => "Ce déploiement est déjà en statut \"{$deploiement->final_statut}\".",
            ], 422);
        }

        if ($this->vmUrl === '') {
            $deploiement->update(['cd_statut' => 'ECHEC', 'logs' => 'DEPLOY_VM_URL non configuré.']);
            $deploiement->recalculerFinalStatut();
            return response()->json(['message' => 'DEPLOY_VM_URL non configuré dans .env.'], 500);
        }

        // Marquer le CD comme en cours et enregistrer qui le lance
        $deploiement->update([
            'cd_statut'               => 'EN_COURS',
            'deploye_sur_serveur_par' => $request->user()->id,
        ]);

        $debutCD = now();

        try {
            $reponseVm = Http::timeout(1800)->post(rtrim($this->vmUrl, '/') . '/deploy', [
                'app'  => $deploiement->app,
                'user' => $request->user()->nom . ' ' . $request->user()->prenom,
            ]);

            Log::info('VM /deploy réponse', [
                'deploiement_id' => $deploiement->id,
                'status'         => $reponseVm->status(),
            ]);

            $data      = $reponseVm->json();
            $cdStatut  = $reponseVm->successful() ? 'SUCCES' : 'ECHEC';
            $logs      = $data['logs'] ?? $reponseVm->body();
            $cdRunId   = $data['cd_run_id'] ?? $data['run_id'] ?? null;
            $finAt     = now();

            $deploiement->update([
                'cd_statut'  => $cdStatut,
                'cd_run_id'  => $cdRunId,
                'logs'       => $logs,
                'fini_a'     => $finAt,
                'duree'      => $deploiement->commence_a
                    ? (int) $deploiement->commence_a->diffInSeconds($finAt)
                    : (int) $debutCD->diffInSeconds($finAt),
            ]);
            $deploiement->recalculerFinalStatut();

            $message = $cdStatut === 'SUCCES'
                ? "✅ Déploiement de \"{$deploiement->app}\" terminé avec succès."
                : "❌ Déploiement de \"{$deploiement->app}\" échoué.";

            return response()->json([
                'message'        => $message,
                'deploiement_id' => $deploiement->id,
                'statut'         => $cdStatut,
                'logs'           => $logs,
            ]);

        } catch (\Exception $e) {
            $deploiement->update([
                'cd_statut' => 'ECHEC',
                'logs'      => "Connexion à la VM impossible : {$e->getMessage()}",
                'fini_a'    => now(),
                'duree'     => $deploiement->commence_a
                    ? (int) $deploiement->commence_a->diffInSeconds(now())
                    : null,
            ]);
            $deploiement->recalculerFinalStatut();

            return response()->json([
                'message'        => "Impossible de joindre la VM : {$e->getMessage()}",
                'deploiement_id' => $deploiement->id,
                'statut'         => 'ECHEC',
            ], 502);
        }
    }

    // -------------------------------------------------------------------------
    // GET /api/deploiements/{id}/logs
    // -------------------------------------------------------------------------

    /**
     * Retourne tous les champs du déploiement pour affichage.
     */
    public function getLogs(int $id): JsonResponse
    {
        $deploiement = Deploiement::with(['declenchePar', 'deployeSurServeurPar', 'projet'])
            ->findOrFail($id);

        return response()->json([
            'deploiement_id'          => $deploiement->id,
            'deployment_id'           => $deploiement->deployment_id,
            'ci_run_id'               => $deploiement->ci_run_id,
            'cd_run_id'               => $deploiement->cd_run_id,
            'nom_projet'              => $deploiement->nom_projet,
            'version_projet'          => $deploiement->version_projet,
            'commit_hash'             => $deploiement->commit_hash,
            'branche'                 => $deploiement->branche,
            'environnement'           => $deploiement->environnement,
            'final_statut'            => $deploiement->final_statut,
            'ci_statut'               => $deploiement->ci_statut,
            'cd_statut'               => $deploiement->cd_statut,
            'package_hash'            => $deploiement->package_hash,
            'nom_package'             => $deploiement->nom_package,
            'logs'                    => $deploiement->logs ?? '',
            'commence_a'              => $deploiement->commence_a,
            'fini_a'                  => $deploiement->fini_a,
            'duree'                   => $deploiement->duree,
            'declenche_par'           => $deploiement->declenchePar
                ? $deploiement->declenchePar->nom . ' ' . $deploiement->declenchePar->prenom
                : null,
            'deploye_sur_serveur_par' => $deploiement->deployeSurServeurPar
                ? $deploiement->deployeSurServeurPar->nom . ' ' . $deploiement->deployeSurServeurPar->prenom
                : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // Méthodes privées
    // -------------------------------------------------------------------------

    /**
     * Lit deploy.meta.json dans un .zip sans extraire les fichiers.
     * Cherche à la racine, dans un sous-dossier, ou dans un zip imbriqué.
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

        if ($contenu === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat || ! str_ends_with(strtolower($stat['name']), '.zip')) {
                    continue;
                }
                $zipInterneContenu = $zip->getFromIndex($i);
                if ($zipInterneContenu === false) {
                    continue;
                }
                $tmpPath = tempnam(sys_get_temp_dir(), 'deploy_inner_') . '.zip';
                file_put_contents($tmpPath, $zipInterneContenu);
                $metaInterne = $this->lireMetaDepuisZip($tmpPath);
                unlink($tmpPath);
                if ($metaInterne !== null) {
                    $zip->close();
                    return $metaInterne;
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
