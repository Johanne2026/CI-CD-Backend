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
