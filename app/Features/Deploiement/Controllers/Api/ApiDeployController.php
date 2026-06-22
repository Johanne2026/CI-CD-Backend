<?php

namespace App\Features\Deploiement\Controllers\Api;

use App\Features\Auth\Models\User;
use App\Features\Deploiement\Models\Deploiement;
use App\Features\Equipes\Models\MembreEquipe;
use App\Features\Notifications\Services\NotificationService;
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
    // GET /api/deploiements
    // -------------------------------------------------------------------------

    /**
     * Liste tous les déploiements.
     * - administrateur       : tous les déploiements
     * - administrateur_cloud_doi / securite : uniquement les déploiements
     *   des projets des équipes dont l'utilisateur est membre
     */
    public function liste(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role === 'administrateur') {
            $deploiements = Deploiement::with(['projet:id,nom', 'declenchePar:id,nom,prenom', 'deployeSurServeurPar:id,nom,prenom'])
                ->orderByDesc('created_at')
                ->get();
        } else {
            $equipeIds = \App\Features\Equipes\Models\MembreEquipe::where('utilisateur_id', $user->id)
                ->pluck('equipe_id');

            $projetIds = Projet::whereIn('equipe_id', $equipeIds)->pluck('id');

            $deploiements = Deploiement::with(['projet:id,nom', 'declenchePar:id,nom,prenom', 'deployeSurServeurPar:id,nom,prenom'])
                ->whereIn('projet_id', $projetIds)
                ->orderByDesc('created_at')
                ->get();
        }

        return response()->json($deploiements);
    }

    // -------------------------------------------------------------------------
    // ÉTAPE 0 — POST /api/projets/{id}/enregistrer-run-ci
    // -------------------------------------------------------------------------

    /**
     * Enregistre un run CI terminé en base de données et importe ses logs.
     * Appelé par le frontend dès qu'il détecte un run GitHub Actions terminé
     * (via polling), AVANT que le .zip soit uploadé.
     *
     * Si un déploiement avec le même ci_run_id existe déjà pour ce projet,
     * retourne l'existant sans doublon.
     *
     * Rôle requis : tous les utilisateurs connectés ayant accès au projet
     *
     * Body JSON :
     *   ci_run_id    : ID du run GitHub Actions (obligatoire)
     *   ci_statut    : "SUCCES" | "ECHEC" | "EN_COURS" (obligatoire)
     *   branche      : branche du run (optionnel)
     *   commit_hash  : SHA du commit (optionnel, max 40 cars)
     *   nom_workflow : nom du workflow (optionnel)
     *   declenche_par: ID utilisateur qui a déclenché le CI (optionnel)
     */
    public function enregistrerRunCI(Request $request, int $id): JsonResponse
    {
        $projet = Projet::findOrFail($id);

        // Vérifier l'accès au projet
        $user = $request->user();
        if ($user->role !== 'administrateur') {
            $estMembre = \App\Features\Equipes\Models\MembreEquipe::where('equipe_id', $projet->equipe_id)
                ->where('utilisateur_id', $user->id)
                ->exists();
            if (! $estMembre) {
                return response()->json(['message' => 'Accès refusé.'], 403);
            }
        }

        $request->validate([
            'ci_run_id'     => ['required', 'integer'],
            'ci_statut'     => ['required', 'in:SUCCES,ECHEC,EN_COURS'],
            'branche'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'commit_hash'   => ['sometimes', 'nullable', 'string', 'max:40'],
            'nom_workflow'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'declenche_par' => ['sometimes', 'nullable', 'integer', 'exists:Utilisateurs,id'],
        ]);

        $ciRunId = (int) $request->input('ci_run_id');
        $ciStatut = $request->input('ci_statut');

        // Éviter les doublons — si ce run CI est déjà en BD pour ce projet, retourner l'existant
        $existant = Deploiement::where('projet_id', $projet->id)
            ->where('ci_run_id', $ciRunId)
            ->first();

        if ($existant) {
            // Mettre à jour le statut CI si changé (ex: EN_COURS → SUCCES)
            if ($existant->ci_statut !== $ciStatut) {
                $existant->update(['ci_statut' => $ciStatut]);
                $existant->recalculerFinalStatut();
            }

            return response()->json([
                'message'        => 'Run CI déjà enregistré.',
                'deploiement_id' => $existant->id,
                'existant'       => true,
                'ci_statut'      => $existant->fresh()->ci_statut,
            ]);
        }

        // Mapper le statut CI vers final_statut
        $finalStatut = match ($ciStatut) {
            'SUCCES'   => 'EN_ATTENTE',  // CI ok, en attente du CD
            'ECHEC'    => 'ECHEC',
            'EN_COURS' => 'EN_COURS',
            default    => 'EN_ATTENTE',
        };

        $deploiement = Deploiement::create([
            'deployment_id'  => "ci_{$ciRunId}",
            'ci_run_id'      => $ciRunId,
            'projet_id'      => $projet->id,
            'nom_projet'     => $projet->nom,
            'branche'        => $request->input('branche'),
            'commit_hash'    => $request->input('commit_hash'),
            'environnement'  => 'PPR',
            'final_statut'   => $finalStatut,
            'ci_statut'      => $ciStatut,
            'cd_statut'      => 'EN_ATTENTE',
            'declenche_par'  => $request->input('declenche_par'),
            'app'            => $projet->nom,
        ]);

        // Importer les logs CI immédiatement si le run est terminé (SUCCES ou ECHEC)
        if (in_array($ciStatut, ['SUCCES', 'ECHEC'])) {
            $this->importerLogsCIDepuisGithub($deploiement);
        }

        return response()->json([
            'message'        => "Run CI #{$ciRunId} enregistré pour \"{$projet->nom}\".",
            'deploiement_id' => $deploiement->id,
            'existant'       => false,
            'ci_statut'      => $ciStatut,
            'logs_importes'  => in_array($ciStatut, ['SUCCES', 'ECHEC']),
        ], 201);
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

        // Extraire ci_run_id depuis deploy.meta.json si non fourni dans le body
        // deploy.meta.json contient ci.run_id (généré par le workflow GitHub Actions)
        $ciRunIdMeta = $meta['ci']['run_id']  ?? $meta['pipeline']['ci_run_id'] ?? null;
        if ($ciRunIdMeta) {
            $ciRunIdMeta = (int) $ciRunIdMeta;
        }

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

        // ci_run_id : priorité au body, sinon depuis deploy.meta.json (ci.run_id)
        $ciRunId = $request->input('ci_run_id')
            ? (int) $request->input('ci_run_id')
            : $ciRunIdMeta;

        // commit_hash / branche : priorité au body, sinon depuis deploy.meta.json
        $commitHashEffectif = $request->input('commit_hash')
            ?? $meta['ci']['commit'] ?? null;
        $brancheEffective = $request->input('branche')
            ?? $meta['ci']['branch'] ?? null;
        $existant = $ciRunId
            ? Deploiement::where('projet_id', $projet->id)->where('ci_run_id', (int) $ciRunId)->first()
            : null;

        if ($existant) {
            // Enrichir l'enregistrement CI existant avec les infos du package
            $existant->update([
                'deployment_id'           => $deploymentId ?? $existant->deployment_id,
                'version_projet'          => $version,
                'commit_hash'             => $commitHashEffectif ?? $existant->commit_hash,
                'branche'                 => $brancheEffective ?? $existant->branche,
                'environnement'           => $environnement,
                'ci_statut'               => 'SUCCES',
                'cd_statut'               => 'EN_ATTENTE',
                'package_hash'            => $request->input('package_hash'),
                'nom_package'             => $request->input('nom_package') ?? $fichierZip->getClientOriginalName(),
                'declenche_par'           => $request->input('declenche_par') ?? $existant->declenche_par,
                'deploye_sur_serveur_par' => $request->user()->id,
                'app'                     => $app,
                'version'                 => $version,
                'commence_a'              => $existant->commence_a ?? now(),
            ]);
            $existant->recalculerFinalStatut();
            $deploiement = $existant->fresh();

            // Importer les logs CI si pas encore fait (run enregistré sans logs)
            $logsDejaPresents = \Illuminate\Support\Facades\DB::table('logs')
                ->where('deploiement_id', $deploiement->id)
                ->where('source', 'CI')
                ->exists();
            if (! $logsDejaPresents) {
                $this->importerLogsCIDepuisGithub($deploiement);
            }
        } else {
            $deploiement = Deploiement::create([
                // Identifiants
                'deployment_id'           => $deploymentId ?? "deploy_{$ciRunId}" ?? "deploy_" . uniqid(),
                'ci_run_id'               => $ciRunId,
                'projet_id'               => $projet->id,

                // Infos projet
                'nom_projet'              => $projet->nom,
                'version_projet'          => $version,
                'commit_hash'             => $commitHashEffectif,
                'branche'                 => $brancheEffective,
                'environnement'           => $environnement,

                // Statuts initiaux
                'final_statut'            => 'EN_ATTENTE',
                'ci_statut'               => 'SUCCES',
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

                'commence_a'              => now(),
            ]);

            // Importer les logs CI depuis GitHub juste après l'upload
            $this->importerLogsCIDepuisGithub($deploiement);
        }

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
            $deploiement->update(['cd_statut' => 'ECHEC']);
            $deploiement->recalculerFinalStatut();
            // Insérer un log d'erreur de configuration dans la table logs
            \Illuminate\Support\Facades\DB::table('logs')->insert([
                'deploiement_id' => $deploiement->id,
                'source'         => 'CD',
                'niveau'         => 'ERROR',
                'etape'          => 'DEPLOY',
                'message'        => 'DEPLOY_VM_URL non configuré.',
                'contenu_ci'     => null,
                'contenu_cd'     => null,
                'created_at'     => now(),
            ]);
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
            $logsTexte = $data['logs'] ?? $reponseVm->body();
            $cdRunId   = $data['cd_run_id'] ?? $data['run_id'] ?? null;
            $finAt     = now();

            $deploiement->update([
                'cd_statut'  => $cdStatut,
                'cd_run_id'  => $cdRunId,
                'fini_a'     => $finAt,
                'duree'      => $deploiement->commence_a
                    ? (int) $deploiement->commence_a->diffInSeconds($finAt)
                    : (int) $debutCD->diffInSeconds($finAt),
            ]);
            $deploiement->recalculerFinalStatut();

            // Insérer les logs CD dans la table logs en une seule fois (pas de concurrence)
            $this->insererLogsCDEnBD($deploiement->id, $logsTexte);

            // Notifier tous les membres de l'équipe du projet + le Cloud DOI déployeur
            $this->envoyerNotificationsDeploiement($deploiement->fresh(), $request->user());

            $message = $cdStatut === 'SUCCES'
                ? "✅ Déploiement de \"{$deploiement->app}\" terminé avec succès."
                : "❌ Déploiement de \"{$deploiement->app}\" échoué.";

            return response()->json([
                'message'        => $message,
                'deploiement_id' => $deploiement->id,
                'statut'         => $cdStatut,
                'logs'           => $logsTexte,
            ]);

        } catch (\Exception $e) {
            $deploiement->update([
                'cd_statut' => 'ECHEC',
                'fini_a'    => now(),
                'duree'     => $deploiement->commence_a
                    ? (int) $deploiement->commence_a->diffInSeconds(now())
                    : null,
            ]);
            $deploiement->recalculerFinalStatut();

            // Insérer l'erreur de connexion dans la table logs
            $this->insererLogsCDEnBD(
                $deploiement->id,
                "CONNEXION VM IMPOSSIBLE : {$e->getMessage()}"
            );

            // Notifier les membres de l'équipe de l'échec
            $this->envoyerNotificationsDeploiement($deploiement->fresh(), $request->user());

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

        // Lire les logs compilés depuis la table logs (colonne contenu_ci/contenu_cd)
        $logsCD = \Illuminate\Support\Facades\DB::table('logs')
            ->where('deploiement_id', $id)
            ->where('source', 'CD')
            ->value('contenu_cd') ?? '';

        $logsCI = \Illuminate\Support\Facades\DB::table('logs')
            ->where('deploiement_id', $id)
            ->where('source', 'CI')
            ->value('contenu_ci') ?? '';

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
            'logs'                    => $logsCD,    // logs CD compilés depuis table logs
            'logs_ci'                 => $logsCI,    // logs CI compilés depuis table logs
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
     * Envoie une notification de résultat de déploiement à tous les membres
     * de l'équipe du projet ainsi qu'à l'administrateur Cloud DOI qui a lancé le CD.
     *
     * Destinataires :
     *  - L'administrateur Cloud DOI déployeur (toujours notifié, même s'il est membre)
     *  - Tous les membres de l'équipe du projet (quel que soit leur rôle)
     */
    private function envoyerNotificationsDeploiement(Deploiement $deploiement, User $cloudDoi): void
    {
        $estSucces   = $deploiement->final_statut === 'SUCCES';
        $nomProjet   = $deploiement->nom_projet ?? $deploiement->app ?? 'Projet inconnu';
        $version     = $deploiement->version_projet ?? $deploiement->version ?? '';
        $label       = $version ? "{$nomProjet} v{$version}" : $nomProjet;

        $titre   = $estSucces
            ? "✅ Déploiement réussi — {$nomProjet}"
            : "❌ Échec du déploiement — {$nomProjet}";

        $message = $estSucces
            ? "L'application \"{$label}\" a été déployée avec succès par {$cloudDoi->prenom} {$cloudDoi->nom}."
            : "Le déploiement de \"{$label}\" a échoué. Consultez les logs pour plus de détails.";

        $type = $estSucces ? 'succes' : 'erreur';

        // Collecter les IDs des destinataires (sans doublons)
        $destinataires = collect();

        // 1. Le Cloud DOI déployeur — toujours notifié
        $destinataires->push($cloudDoi->id);

        // 2. Tous les membres de l'équipe du projet
        if ($deploiement->projet_id) {
            $projet = $deploiement->projet;
            if ($projet) {
                $membreIds = MembreEquipe::where('equipe_id', $projet->equipe_id)
                    ->pluck('utilisateur_id');
                $destinataires = $destinataires->merge($membreIds);
            }
        }

        // Envoyer à chaque destinataire unique
        foreach ($destinataires->unique() as $utilisateurId) {
            try {
                NotificationService::envoyer($utilisateurId, $titre, $message, $type);
            } catch (\Exception $e) {
                Log::warning('Notification déploiement non envoyée', [
                    'utilisateur_id' => $utilisateurId,
                    'error'          => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Parse les logs CD retournés par deploy.ps1 et les insère dans la table logs.
     * Appelé une seule fois à la fin du déploiement — pas de concurrence.
     *
     * Format attendu : "2026-06-12 10:00:00 | [ETAPE] message"
     * ou texte brut ligne par ligne.
     */
    private function insererLogsCDEnBD(int $deploiementId, string $logsTexte): void
    {
        if (empty(trim($logsTexte))) {
            return;
        }

        // 1. Créer (ou récupérer) l'entrée résumé dans logs
        $logResume = \Illuminate\Support\Facades\DB::table('logs')
            ->where('deploiement_id', $deploiementId)
            ->where('source', 'CD')
            ->first();

        if (! $logResume) {
            $logId = \Illuminate\Support\Facades\DB::table('logs')->insertGetId([
                'deploiement_id' => $deploiementId,
                'source'         => 'CD',
                'niveau'         => 'INFO',
                'contenu_ci'     => null,
                'contenu_cd'     => $logsTexte,
                'created_at'     => now(),
            ]);
        } else {
            \Illuminate\Support\Facades\DB::table('logs')
                ->where('id', $logResume->id)
                ->update(['contenu_cd' => $logsTexte]);
            $logId = $logResume->id;
        }

        // 2. Insérer les lignes individuelles dans log_details
        $inserts    = [];
        $maintenant = now();
        $niveauGlobal = 'INFO';

        foreach (explode("\n", $logsTexte) as $ligne) {
            $ligne = rtrim($ligne);
            if ($ligne === '') {
                continue;
            }

            $timestamp = $maintenant;
            $etape     = null;
            $niveau    = 'INFO';
            $message   = $ligne;

            if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s*\|\s*(.+)$/', $ligne, $m)) {
                try { $timestamp = \Carbon\Carbon::parse($m[1]); } catch (\Exception) {}
                $message = $m[2];
            }

            $lower = strtolower($message);
            if (str_contains($lower, 'error') || str_contains($lower, 'echec') || str_contains($lower, 'echoue') || str_contains($lower, 'failed')) {
                $niveau = 'ERROR';
                $niveauGlobal = 'ERROR';
            } elseif (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
                $niveau = 'WARNING';
                if ($niveauGlobal !== 'ERROR') $niveauGlobal = 'WARNING';
            }

            foreach (['VALIDATE', 'BACKUP', 'INSTALL', 'CONFIGURE', 'MIGRATE', 'VERIFY', 'ROLLBACK', 'DEPLOY', 'ERROR'] as $step) {
                if (str_contains(strtoupper($message), $step)) {
                    $etape = $step;
                    break;
                }
            }

            $inserts[] = [
                'log_id'     => $logId,
                'source'     => 'CD',
                'niveau'     => $niveau,
                'etape'      => $etape,
                'message'    => mb_substr($message, 0, 65535),
                'created_at' => $timestamp,
            ];

            if (count($inserts) >= 200) {
                \Illuminate\Support\Facades\DB::table('log_details')->insert($inserts);
                $inserts = [];
            }
        }

        if (! empty($inserts)) {
            \Illuminate\Support\Facades\DB::table('log_details')->insert($inserts);
        }

        // Mettre à jour le niveau global dans logs
        \Illuminate\Support\Facades\DB::table('logs')
            ->where('id', $logId)
            ->update(['niveau' => $niveauGlobal]);
    }

    /**
     * Importe automatiquement les logs CI depuis GitHub.
     * Utilise le token GitHub de l'administrateur qui a créé le projet (stocké chiffré en BD).
     * Silencieux si token absent ou GitHub inaccessible.
     */
    private function importerLogsCIDepuisGithub(Deploiement $deploiement): void
    {
        if (! $deploiement->ci_run_id || ! $deploiement->projet_id) {
            return;
        }

        $projet = $deploiement->projet;
        if (! $projet || ! $projet->url_depot) {
            return;
        }

        // Utiliser le token de l'admin créateur du projet, sinon GITHUB_TOKEN .env
        $token = '';
        if ($projet->cree_par_id) {
            $admin = \App\Features\Auth\Models\User::find($projet->cree_par_id);
            if ($admin && $admin->role === 'administrateur') {
                $token = $admin->tokenGithubEffectif();
            }
        }
        if (! $token) {
            $token = config('services.github.token', '');
        }

        if (! $token) {
            return;
        }

        try {
            [$owner, $repo] = $this->parseDepotUrlLocal($projet->url_depot);
        } catch (\Exception) {
            return;
        }

        try {
            $http = \Illuminate\Support\Facades\Http::withToken($token)
                ->withHeaders([
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'Laravel-CICD-App',
                ])
                ->timeout(60);

            if (env('HTTPS_PROXY')) {
                $http = $http->withOptions(['proxy' => env('HTTPS_PROXY')]);
            }

            $response = $http->withoutRedirecting()->get(
                "https://api.github.com/repos/{$owner}/{$repo}/actions/runs/{$deploiement->ci_run_id}/logs"
            );

            $zipContenu = null;
            if ($response->status() === 302) {
                $zipResponse = \Illuminate\Support\Facades\Http::timeout(60)->get($response->header('Location'));
                if ($zipResponse->successful()) {
                    $zipContenu = $zipResponse->body();
                }
            } elseif ($response->successful()) {
                $zipContenu = $response->body();
            }

            if (! $zipContenu) {
                return;
            }

            $this->parserEtInsererLogsCIDepuisZip($zipContenu, $deploiement->id);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Import logs CI échoué', [
                'deploiement_id' => $deploiement->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    private function parserEtInsererLogsCIDepuisZip(string $zipContenu, int $deploiementId): void
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'ci_logs_') . '.zip';
        file_put_contents($tmpZip, $zipContenu);

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            unlink($tmpZip);
            return;
        }

        $inserts      = [];
        $lignesTexte  = [];
        $niveauGlobal = 'INFO';

        // 1. Créer (ou récupérer) l'entrée résumé CI dans logs
        $logResume = \Illuminate\Support\Facades\DB::table('logs')
            ->where('deploiement_id', $deploiementId)
            ->where('source', 'CI')
            ->first();

        if (! $logResume) {
            $logId = \Illuminate\Support\Facades\DB::table('logs')->insertGetId([
                'deploiement_id' => $deploiementId,
                'source'         => 'CI',
                'niveau'         => 'INFO',
                'contenu_ci'     => null,
                'contenu_cd'     => null,
                'created_at'     => now(),
            ]);
        } else {
            $logId = $logResume->id;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! $stat || ! str_ends_with(strtolower($stat['name']), '.txt')) {
                continue;
            }

            $texte = $zip->getFromIndex($i);
            if ($texte === false) {
                continue;
            }

            $etape = strtoupper(preg_replace('/^\d+_/', '', basename($stat['name'], '.txt')));
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
                    \Illuminate\Support\Facades\DB::table('log_details')->insert($inserts);
                    $inserts = [];
                }
            }
        }

        $zip->close();
        unlink($tmpZip);

        if (! empty($inserts)) {
            \Illuminate\Support\Facades\DB::table('log_details')->insert($inserts);
        }

        // 3. Mettre à jour le résumé dans logs (contenu_ci compilé + niveau global)
        if (! empty($lignesTexte)) {
            \Illuminate\Support\Facades\DB::table('logs')
                ->where('id', $logId)
                ->update([
                    'contenu_ci' => implode("\n", $lignesTexte),
                    'niveau'     => $niveauGlobal,
                ]);
        }
    }

    private function parseDepotUrlLocal(string $url): array
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
