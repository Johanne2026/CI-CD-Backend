<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            // Supprime l'ancien index unique non-nullable
            $table->dropUnique(['username_outil_cicd']);

            // Recrée la colonne nullable avec un index unique qui tolère plusieurs NULL
            $table->string('username_outil_cicd')
                  ->nullable()
                  ->unique()
                  ->comment("Nom d'utilisateur GitHub")
                  ->change();
        });
    }

    public function down(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->string('username_outil_cicd')
                  ->nullable(false)
                  ->unique()
                  ->comment("Nom d'utilisateur GitHub")
                  ->change();
        });
    }
};
