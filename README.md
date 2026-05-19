Pour créer le projet : laravel new nom_du_projet
Après, installer les dépendances : composer install --no-dev
Après, générer la clé de projet : php artisan key:generate
Après, exécuter les migrations : php artisan migrate
Lancer le serveur local : php artisan serve

Installer Node.js : 
Pour se rassurer de la version de Node.js installée : npm -v 
Installer toutes les dépendances (inclut Vite) :  npm install
 npm audit fix
Lancer le build : npm run build

installer postcss : npm install --save-dev postcss postcss-preset-env

Nous avons choisi d'organiser nos fichiers de projet par features.
Donc là nous avons décidé de créer un dossier nommé "Features" dans le dossier "app", qui nous permettra d'organiser notre code suivant cette architecture.
Puis nous avons créé un dossier "Auth" qui nous permettra de gérer l'authentification. Par défaut, Laravel qui est une super boîte à outils pour développer nos applications, a un système d'authentification tout prêt et fonctionnel faisant partie de son starter pack.
Pour respecter notre architecture, nous avons de déplacer tous les fichiers par défaut du starter pack d'authentification vers notre dossier "Auth".
Il est organisé comme suit : 

app/Features/Auth/
├── Controllers/
│   ├── Settings/
│   │   ├── PasswordController.php
│   │   └── ProfileController.php
│   ├── AuthenticatedSessionController.php
│   ├── ConfirmablePasswordController.php
│   ├── EmailVerificationNotificationController.php
│   ├── EmailVerificationPromptController.php
│   ├── NewPasswordController.php
│   ├── PasswordResetLinkController.php
│   ├── RegisteredUserController.php
│   └── VerifyEmailController.php
├── Models/
│   └── User.php
├── Providers/
│   └── AuthServiceProvider.php
├── Requests/
│   ├── Settings/
│   │   └── ProfileUpdateRequest.php
│   └── LoginRequest.php
└── Routes/
    ├── auth.php
    └── settings.php

- Gestion de l'authentification coté Backend : 

Nous allons faire des APIs pour gérer l'authentification pour plus de sécurité.

Ce qu'on va faire

Le projet utilise actuellement Inertia.js (SSR couplé), mais tu veux une API REST pour connecter un frontend séparé (React, Vue, mobile, etc.).

On va utiliser Laravel Sanctum qui est déjà inclus dans Laravel 12. Sanctum gère l'auth API via des tokens Bearer — le frontend envoie ses credentials, reçoit un token, et l'inclut dans chaque requête suivante.

Les étapes :

Créer le fichier de routes API routes/api.php et le brancher dans bootstrap/app.php
Créer les controllers API dans app/Features/Auth/Controllers/Api/
Les endpoints exposés : POST /api/register, POST /api/login, POST /api/logout, GET /api/user


Nous allons maintenant désactiver les vues (blade) du backend pour autoriser uniquement les api qui permettront de faire passer les informations du frontend au backend de façon sécurisée.


Pour se faire, nous allons vider toutes les routes "Inertia/auth" du fichier "web.php" dans le dossier "routes" et vider les deux fichiers de routes web "auth" et "settings" de "Features\Auth" pour n'utiliser que les routes d'api.
Puis, on retire le middleware Inertia de bootstrap/app.php puisqu'il ne sert plus à rien.


- Gestion de la liaison Frontend-Backend

Nous allons maintenant lier le frontend au backend grâce au fichier cors.php qui sera dans le dossier "config". 
Ce fichier permet d'autoriser les requêtes cross-origin depuis le frontend.

Nous allons aussi configurer le fichier "sanctum.php" du dossier "config" pour configurer pour les tokens API. Cette configuration des tokens API est faite avec expiration configurable via SANCTUM_TOKEN_EXPIRATION.

Nous allons brancher le middleware CORS dans bootstrap/app.php. Le middleware CORS doit s'appliquer sur toutes les requêtes API 

Ajouter CORS_ALLOWED_ORIGINS dans .env. Faire pareil dans .env.example pour que les autres devs sachent quoi configurer : variables FRONTEND_URL et CORS_ALLOWED_ORIGINS ajoutées.

