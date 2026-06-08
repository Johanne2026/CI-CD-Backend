<?php

return [
    /*
    |--------------------------------------------------------------------------
    | URL de l'API REST de la VM
    |--------------------------------------------------------------------------
    | Le backend appelle /upload puis /deploy sur cette base.
    | Exemple : http://192.168.1.50:5000
    */
    'vm_url' => env('DEPLOY_VM_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Secret partagé pour le callback de fin de déploiement
    |--------------------------------------------------------------------------
    | deploy.ps1 envoie ce secret dans le header X-Deploy-Secret
    | quand il notifie le backend de la fin du déploiement.
    | Laisser vide pour désactiver la vérification (déconseillé).
    */
    'callback_secret' => env('DEPLOY_CALLBACK_SECRET', ''),
];
