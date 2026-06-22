<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            // Remplacer la colonne générique "contenu" par deux colonnes dédiées :
            // contenu_ci : texte brut compilé des logs CI (GitHub Actions) pour ce déploiement
            // contenu_cd : texte brut compilé des logs CD (deploy.ps1) pour ce déploiement
            $table->longText('contenu_ci')->nullable()->after('message')
                  ->comment('Logs CI compilés (GitHub Actions) — null pour les entrées ligne par ligne');
            $table->longText('contenu_cd')->nullable()->after('contenu_ci')
                  ->comment('Logs CD compilés (deploy.ps1) — null pour les entrées ligne par ligne');
        });

        // ── Migrer les données depuis contenu vers contenu_ci / contenu_cd ─────
        DB::statement("
            UPDATE logs
            SET contenu_ci = contenu
            WHERE source = 'CI'
              AND contenu IS NOT NULL
        ");

        DB::statement("
            UPDATE logs
            SET contenu_cd = contenu
            WHERE source = 'CD'
              AND contenu IS NOT NULL
        ");

        // ── Supprimer l'ancienne colonne contenu ──────────────────────────────
        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn('contenu');
        });
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->longText('contenu')->nullable()->after('message');
        });

        DB::statement("
            UPDATE logs SET contenu = contenu_ci WHERE source = 'CI' AND contenu_ci IS NOT NULL
        ");
        DB::statement("
            UPDATE logs SET contenu = contenu_cd WHERE source = 'CD' AND contenu_cd IS NOT NULL
        ");

        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn(['contenu_ci', 'contenu_cd']);
        });
    }
};
