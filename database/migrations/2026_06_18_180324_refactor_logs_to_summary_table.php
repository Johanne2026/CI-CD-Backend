<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // À ce stade, la migration précédente (175926) a déjà supprimé
        // etape, message, source, niveau de la table logs.
        // La table logs contient maintenant : id, deploiement_id, contenu_ci, contenu_cd, created_at

        // Dédupliquer : garder uniquement la ligne la plus récente par deploiement_id
        DB::statement("
            DELETE l1 FROM logs l1
            INNER JOIN logs l2
            ON l1.deploiement_id = l2.deploiement_id
            WHERE l1.id < l2.id
        ");

        // Réintroduire source et niveau pour distinguer CI vs CD dans le résumé
        Schema::table('logs', function (Blueprint $table) {
            $table->enum('source', ['CI', 'CD'])->after('deploiement_id')->default('CI');
            $table->enum('niveau', ['INFO', 'WARNING', 'ERROR'])
                  ->default('INFO')->after('source')
                  ->comment('Niveau global : ERROR si au moins une ligne est ERROR');
            // Contrainte unique : un résumé CI + un résumé CD par déploiement
            $table->unique(['deploiement_id', 'source'], 'logs_deploiement_source_unique');
        });

        // Mettre source=CI/CD selon la présence du contenu
        DB::statement("UPDATE logs SET source='CI' WHERE contenu_ci IS NOT NULL");
        DB::statement("UPDATE logs SET source='CD' WHERE contenu_cd IS NOT NULL AND contenu_ci IS NULL");
    }

    public function down(): void
    {
        Schema::table('logs', function (Blueprint $table) {
            $table->dropUnique('logs_deploiement_source_unique');
            $table->dropColumn(['source', 'niveau']);
        });

        Schema::table('logs', function (Blueprint $table) {
            $table->enum('source', ['CI', 'CD'])->after('deploiement_id');
            $table->enum('niveau', ['INFO', 'WARNING', 'ERROR'])->default('INFO')->after('source');
            $table->string('etape', 100)->nullable()->after('niveau');
            $table->text('message')->after('etape');
        });
    }
};
