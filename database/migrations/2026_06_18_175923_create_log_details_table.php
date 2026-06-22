<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Créer log_details (lignes individuelles) ──────────────────────
        Schema::create('log_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('log_id')
                  ->comment('Référence vers logs.id (résumé du déploiement)');
            $table->enum('source', ['CI', 'CD']);
            $table->enum('niveau', ['INFO', 'WARNING', 'ERROR'])->default('INFO');
            $table->string('etape', 100)->nullable();
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['log_id', 'source']);
            $table->index('etape');

            $table->foreign('log_id')
                  ->references('id')
                  ->on('logs')
                  ->cascadeOnDelete();
        });

        // ── 2. Migrer les lignes individuelles de logs → log_details ─────────
        // Chaque ligne individuelle (message non-COMPILE) doit être liée
        // à l'entrée COMPILE correspondante de son déploiement.
        // On crée d'abord les entrées logs manquantes (déploiements sans COMPILE).

        // Pour chaque deploiement_id ayant des lignes individuelles,
        // s'assurer qu'il existe une entrée COMPILE dans logs.
        DB::statement("
            INSERT IGNORE INTO logs (deploiement_id, source, niveau, contenu_ci, contenu_cd, created_at)
            SELECT DISTINCT
                l.deploiement_id,
                'CI',
                'INFO',
                NULL,
                NULL,
                MIN(l.created_at)
            FROM logs l
            WHERE l.etape != 'COMPILE' AND l.source = 'CI'
              AND NOT EXISTS (
                SELECT 1 FROM logs c
                WHERE c.deploiement_id = l.deploiement_id AND c.etape = 'COMPILE' AND c.source = 'CI'
              )
            GROUP BY l.deploiement_id
        ");

        // Insérer les lignes CI dans log_details liées à leur entrée logs parent
        DB::statement("
            INSERT INTO log_details (log_id, source, niveau, etape, message, created_at)
            SELECT
                parent.id,
                ligne.source,
                ligne.niveau,
                ligne.etape,
                ligne.message,
                ligne.created_at
            FROM logs ligne
            JOIN logs parent ON parent.deploiement_id = ligne.deploiement_id
                             AND parent.source = ligne.source
                             AND parent.etape = 'COMPILE'
            WHERE ligne.etape != 'COMPILE'
              AND ligne.message IS NOT NULL
              AND ligne.source = 'CI'
        ");

        // Insérer les lignes CD dans log_details
        DB::statement("
            INSERT IGNORE INTO logs (deploiement_id, source, niveau, contenu_ci, contenu_cd, created_at)
            SELECT DISTINCT
                l.deploiement_id,
                'CD',
                'INFO',
                NULL,
                NULL,
                MIN(l.created_at)
            FROM logs l
            WHERE l.etape != 'COMPILE' AND l.source = 'CD'
              AND NOT EXISTS (
                SELECT 1 FROM logs c
                WHERE c.deploiement_id = l.deploiement_id AND c.etape = 'COMPILE' AND c.source = 'CD'
              )
            GROUP BY l.deploiement_id
        ");

        DB::statement("
            INSERT INTO log_details (log_id, source, niveau, etape, message, created_at)
            SELECT
                parent.id,
                ligne.source,
                ligne.niveau,
                ligne.etape,
                ligne.message,
                ligne.created_at
            FROM logs ligne
            JOIN logs parent ON parent.deploiement_id = ligne.deploiement_id
                             AND parent.source = ligne.source
                             AND parent.etape = 'COMPILE'
            WHERE ligne.etape != 'COMPILE'
              AND ligne.message IS NOT NULL
              AND ligne.source = 'CD'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('log_details');
    }
};
