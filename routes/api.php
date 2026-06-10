<?php

use App\Features\Auth\Controllers\Api\ApiAuthController;
use App\Features\Auth\Controllers\Api\ApiUserController;
use App\Features\Deploiement\Controllers\Api\ApiDeployController;
use App\Features\Equipes\Controllers\Api\ApiEquipeController;
use App\Features\Notifications\Controllers\Api\ApiNotificationController;
use App\Features\Projets\Controllers\Api\ApiProjetController;
use App\Features\Projets\Controllers\Api\ApiWorkflowController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/register', [ApiAuthController::class, 'register']);
Route::post('/login',    [ApiAuthController::class, 'login']);

// Callback VM — supprimé, approche synchrone utilisée à la place

// Preflight OPTIONS pour les requêtes multipart/form-data (upload .zip)
Route::options('/projets/{id}/upload-zip', fn() => response()->json([], 200));

// Routes protégées — nécessitent un token valide
Route::middleware('auth:api')->group(function () {

    // Auth
    Route::get('/user',         [ApiUserController::class, 'show']);
    Route::get('/utilisateurs', [ApiUserController::class, 'index']);
    Route::put('/user',         [ApiUserController::class, 'update']);
    Route::post('/logout',      [ApiAuthController::class, 'logout']);

    // Équipes — lecture (tous les utilisateurs connectés)
    Route::get('/equipes',       [ApiEquipeController::class, 'index']);
    Route::get('/equipes/{id}',  [ApiEquipeController::class, 'show']);

    // Équipes — écriture (administrateur uniquement)
    Route::middleware('admin')->group(function () {
        Route::post('/equipes',                                        [ApiEquipeController::class, 'store']);
        Route::put('/equipes/{id}',                                    [ApiEquipeController::class, 'update']);
        Route::delete('/equipes/{id}',                                 [ApiEquipeController::class, 'destroy']);
        Route::get('/equipes/{id}/utilisateurs-disponibles',           [ApiEquipeController::class, 'utilisateursDispo']);
        Route::post('/equipes/{id}/membres',                           [ApiEquipeController::class, 'ajouterMembre']);
        Route::delete('/equipes/{id}/membres/{userId}',                [ApiEquipeController::class, 'retirerMembre']);
    });

    // Projets — lecture (tous les utilisateurs connectés)
    Route::get('/projets',       [ApiProjetController::class, 'index']);
    Route::get('/projets/{id}',  [ApiProjetController::class, 'show']);

    // Projets — écriture (administrateur uniquement)
    Route::middleware('admin')->group(function () {
        Route::post('/projets',                        [ApiProjetController::class, 'store']);
        Route::put('/projets/{id}',                    [ApiProjetController::class, 'update']);
        Route::patch('/projets/{id}/archiver',         [ApiProjetController::class, 'archiver']);
        Route::delete('/projets/{id}',                 [ApiProjetController::class, 'destroy']);
    });

    // Connexion GitHub d'un projet (tous les utilisateurs connectés)
    Route::post('/projets/{id}/connecter-depot',                   [ApiProjetController::class,  'connecterDepot']);

    // Génération de clé de déploiement (administrateur uniquement)
    Route::middleware('admin')->group(function () {
        Route::post('/projets/{id}/generer-cle-deploiement',       [ApiProjetController::class, 'genererCleDeploiement']);
    });

    // Templates GitHub Actions (tous les utilisateurs connectés)
    Route::get('/workflows/templates',                             [ApiWorkflowController::class, 'templates']);
    Route::get('/workflows/templates/{fichier}',                   [ApiWorkflowController::class, 'templateContenu']);

    // Workflows GitHub (tous les utilisateurs connectés)
    Route::post('/projets/{id}/workflows/sync',                    [ApiWorkflowController::class, 'sync']);
    Route::post('/projets/{id}/workflows/depuis-template',         [ApiWorkflowController::class, 'depuisTemplate']);
    Route::get('/projets/{id}/workflows/{workflowId}/runs',        [ApiWorkflowController::class, 'runs']);

    // Artifacts CI — administrateur uniquement
    Route::get('/projets/{id}/workflows/runs/{runId}/artifacts',              [ApiWorkflowController::class, 'artifacts']);
    Route::get('/projets/{id}/workflows/artifacts/{artifactId}/download',     [ApiWorkflowController::class, 'downloadArtifact']);

    // Déploiements — Étape 1 : upload du .zip vers la VM
    Route::post('/projets/{id}/upload-zip',    [ApiDeployController::class, 'uploadZip']);
    // Déploiements — Étape 2 : lancer deploy.ps1 (synchrone — attend la fin)
    Route::post('/deploiements/{id}/lancer',   [ApiDeployController::class, 'lancerDeploi']);
    // Déploiements — Relecture des logs en BD
    Route::get('/deploiements/{id}/logs',      [ApiDeployController::class, 'getLogs']);

    // Notifications
    Route::get('/notifications',                        [ApiNotificationController::class, 'index']);
    Route::patch('/notifications/lire-toutes',          [ApiNotificationController::class, 'marquerToutesLues']);
    Route::patch('/notifications/{id}/lire',            [ApiNotificationController::class, 'marquerLue']);
    Route::delete('/notifications/lues',                [ApiNotificationController::class, 'supprimerLues']);
    Route::delete('/notifications/{id}',                [ApiNotificationController::class, 'destroy']);

});
