<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            // MySQL : il faut d'abord supprimer la FK, puis l'index unique, puis recréer la FK
            $table->dropForeign(['equipe_id']);
            $table->dropUnique(['equipe_id']);

            // Recréer la FK sans contrainte unique — une équipe peut avoir plusieurs projets
            $table->foreign('equipe_id')
                  ->references('id')
                  ->on('Equipes')
                  ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            $table->dropForeign(['equipe_id']);
            $table->unique('equipe_id');
            $table->foreign('equipe_id')
                  ->references('id')
                  ->on('Equipes')
                  ->cascadeOnDelete();
        });
    }
};
