<?php

use App\Features\Auth\Controllers\Api\ApiAuthController;
use App\Features\Auth\Controllers\Api\ApiUserController;
use App\Features\Equipes\Controllers\Api\ApiEquipeController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/register', [ApiAuthController::class, 'register']);
Route::post('/login',    [ApiAuthController::class, 'login']);

// Routes protégées — nécessitent un token valide
Route::middleware('auth:api')->group(function () {

    // Auth
    Route::get('/user',    [ApiUserController::class, 'show']);
    Route::post('/logout', [ApiAuthController::class, 'logout']);

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
});
