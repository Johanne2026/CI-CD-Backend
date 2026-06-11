<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supprimer la contrainte FK sur deploye_sur_serveur_par si elle existait
        // puis recréer la table avec la bonne structure en SQL brut

        // 1. Sauvegarder les données existantes
        $existants = DB::table('deploiements')->get();

        // 2. Supprimer la table existante (avec ses contraintes)
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('deploiements');
        Schema::enableForeignKeyConstraints();

        // 3. Créer la nouvelle table avec la structure complète
        DB::statement("
            CREATE TABLE `deploiements` (
                `id`                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `deployment_id`           VARCHAR(100)    NULL     COMMENT 'ID logique depuis deploy.meta.json. Ex: deploy_27198809191',
                `ci_run_id`               BIGINT UNSIGNED NULL     COMMENT 'ID du run GitHub Actions CI. Ex: 27198809191',
                `cd_run_id`               VARCHAR(100)    NULL     COMMENT 'ID du run CD sur la VM',
                `projet_id`               BIGINT UNSIGNED NULL,
                `nom_projet`              VARCHAR(255)    NULL,
                `version_projet`          VARCHAR(100)    NULL,
                `commit_hash`             VARCHAR(40)     NULL     COMMENT 'SHA du commit déployé',
                `branche`                 VARCHAR(255)    NULL,
                `environnement`           VARCHAR(50)     NOT NULL DEFAULT 'PPR' COMMENT 'PPR, PROD, DEV...',
                `final_statut`            ENUM('EN_ATTENTE','EN_COURS','SUCCES','ECHEC') NOT NULL DEFAULT 'EN_ATTENTE',
                `ci_statut`               ENUM('EN_ATTENTE','EN_COURS','SUCCES','ECHEC') NOT NULL DEFAULT 'EN_ATTENTE',
                `cd_statut`               ENUM('EN_ATTENTE','EN_COURS','SUCCES','ECHEC') NOT NULL DEFAULT 'EN_ATTENTE',
                `package_hash`            VARCHAR(64)     NULL     COMMENT 'Hash artifact. Ex: 231e37349ae2eeac...',
                `nom_package`             VARCHAR(255)    NULL     COMMENT 'Nom du package obtenu à la fin du CI',
                `logs`                    LONGTEXT        NULL,
                `commence_a`              TIMESTAMP       NULL     COMMENT 'Date et heure de début du déploiement CI',
                `fini_a`                  TIMESTAMP       NULL     COMMENT 'Date et heure de fin du déploiement CD',
                `duree`                   INT UNSIGNED    NULL     COMMENT 'Durée totale CI + CD en secondes',
                `declenche_par`           BIGINT UNSIGNED NULL     COMMENT 'Utilisateur qui a lancé le pipeline CI',
                `deploye_sur_serveur_par` BIGINT UNSIGNED NULL     COMMENT 'Utilisateur Cloud DOI qui a lancé le CD',
                `app`                     VARCHAR(255)    NULL     COMMENT 'Conservé pour compatibilité',
                `version`                 VARCHAR(255)    NULL     DEFAULT 'latest' COMMENT 'Conservé pour compatibilité',
                `created_at`              TIMESTAMP       NULL,
                `updated_at`              TIMESTAMP       NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_deploiements_projet`
                    FOREIGN KEY (`projet_id`) REFERENCES `Projets`(`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_deploiements_declenche_par`
                    FOREIGN KEY (`declenche_par`) REFERENCES `Utilisateurs`(`id`) ON DELETE SET NULL,
                CONSTRAINT `fk_deploiements_deploye_sur_serveur_par`
                    FOREIGN KEY (`deploye_sur_serveur_par`) REFERENCES `Utilisateurs`(`id`) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // 4. Réinsérer les données existantes en mappant les anciens champs
        foreach ($existants as $row) {
            $finalStatut = match ($row->statut ?? 'en_attente') {
                'en_attente' => 'EN_ATTENTE',
                'en_cours'   => 'EN_COURS',
                'termine'    => 'SUCCES',
                'echoue'     => 'ECHEC',
                default      => 'EN_ATTENTE',
            };

            DB::table('deploiements')->insert([
                'id'                      => $row->id,
                'projet_id'               => $row->projet_id ?? null,
                'app'                     => $row->app ?? null,
                'version'                 => $row->version ?? 'latest',
                'ci_run_id'               => $row->github_run_id ?? null,
                'logs'                    => $row->logs ?? null,
                'final_statut'            => $finalStatut,
                'ci_statut'               => 'SUCCES',  // données historiques considérées comme terminées
                'cd_statut'               => $finalStatut === 'SUCCES' ? 'SUCCES' : 'EN_ATTENTE',
                'declenche_par'           => $row->lance_par ?? null,
                'created_at'              => $row->created_at ?? now(),
                'updated_at'              => $row->updated_at ?? now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('deploiements');
        Schema::enableForeignKeyConstraints();

        // Recréer l'ancienne structure minimale
        DB::statement("
            CREATE TABLE `deploiements` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `projet_id`  BIGINT UNSIGNED NOT NULL,
                `lance_par`  BIGINT UNSIGNED NOT NULL,
                `app`        VARCHAR(255)    NOT NULL,
                `version`    VARCHAR(255)    NOT NULL DEFAULT 'latest',
                `statut`     ENUM('en_attente','en_cours','termine','echoue') NOT NULL DEFAULT 'en_attente',
                `logs`       LONGTEXT        NULL,
                `created_at` TIMESTAMP       NULL,
                `updated_at` TIMESTAMP       NULL,
                PRIMARY KEY (`id`),
                CONSTRAINT `fk_dep_projet` FOREIGN KEY (`projet_id`) REFERENCES `Projets`(`id`),
                CONSTRAINT `fk_dep_user`   FOREIGN KEY (`lance_par`) REFERENCES `Utilisateurs`(`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
