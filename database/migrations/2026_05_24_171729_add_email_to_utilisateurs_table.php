<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            // La colonne existe déjà — on ajoute uniquement l'index unique
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
