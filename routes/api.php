<?php

use App\Features\Auth\Controllers\Api\ApiAuthController;
use App\Features\Auth\Controllers\Api\ApiUserController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/register', [ApiAuthController::class, 'register']);
Route::post('/login',    [ApiAuthController::class, 'login']);

// Routes protégées — nécessitent un token valide
Route::middleware('auth:api')->group(function () {
    Route::get('/user',    [ApiUserController::class, 'show']);
    Route::post('/logout', [ApiAuthController::class, 'logout']);
});
