<?php

return [
    /*
    |--------------------------------------------------------------------------
    | URL de l'API REST de la VM
    |--------------------------------------------------------------------------
    | Le backend appelle /upload, /deploy et /sync-keys sur cette base.
    | Exemple : http://192.168.1.50:5000
    */
    'vm_url' => env('DEPLOY_VM_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Secret partagé pour sécuriser les appels backend → API VM
    |--------------------------------------------------------------------------
    | Envoyé dans le header X-Deploy-Secret sur tous les appels vers la VM.
    | Doit être identique dans .env et dans l'API PowerShell sur la VM.
    */
    'callback_secret' => env('DEPLOY_CALLBACK_SECRET', ''),
];
