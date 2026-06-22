<?php

use Illuminate\Support\Facades\Route;

// ── Documentation Swagger UI ──────────────────────────────────────────────────
// Accessible via http://localhost:8000/docs
Route::get('/docs', function () {
    return response()->file(base_path('swagger-ui.html'));
});

// Sert le fichier openapi.yaml pour Swagger UI
Route::get('/openapi.yaml', function () {
    return response()->file(base_path('openapi.yaml'), [
        'Content-Type' => 'application/yaml',
    ]);
});
