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
            // Texte brut compilé de tous les logs d'une source pour un déploiement.
            // Peuplé par une entrée spéciale : etape = 'COMPILE', message = '[résumé]'.
            // Les entrées ligne par ligne gardent contenu = null.
            $table->longText('contenu')->nullable()->after('message')
                  ->comment('Texte brut compilé (CI ou CD) — null pour les entrées ligne par ligne');
        });

        // ── Migration des données existantes ──────────────────────────────────
        // Copier deploiements.logs_ci → logs (source=CI, etape=COMPILE, contenu=...)
        DB::statement("
            INSERT INTO logs (deploiement_id, source, niveau, etape, message, contenu, created_at)
            SELECT
                id,
                'CI',
                'INFO',
                'COMPILE',
                '[Logs CI compilés]',
                logs_ci,
                created_at
            FROM deploiements
            WHERE logs_ci IS NOT NULL
              AND logs_ci != ''
        ");

        // Copier deploiements.logs → logs (source=CD, etape=COMPILE, contenu=...)
        DB::statement("
            INSERT INTO logs (deploiement_id, source, niveau, etape, message, contenu, created_at)
            SELECT
                id,
                'CD',
                'INFO',
                'COMPILE',
                '[Logs CD compilés]',
                logs,
                created_at
            FROM deploiements
            WHERE logs IS NOT NULL
              AND logs != ''
        ");
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->dropColumn('contenu');
        });
    }
};
