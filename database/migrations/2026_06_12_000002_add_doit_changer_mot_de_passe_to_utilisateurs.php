<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->boolean('doit_changer_mot_de_passe')
                  ->default(false)
                  ->after('role')
                  ->comment('true = l\'utilisateur doit changer son mot de passe à la prochaine connexion');
        });
    }

    public function down(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->dropColumn('doit_changer_mot_de_passe');
        });
    }
};
