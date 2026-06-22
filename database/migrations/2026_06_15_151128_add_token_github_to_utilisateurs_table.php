<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            // Token GitHub chiffré en AES-256 via le cast "encrypted" de Laravel.
            // Uniquement pour les administrateurs.
            $table->text('token_github')->nullable()->after('username_outil_cicd');
        });
    }

    public function down(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->dropColumn('token_github');
        });
    }
};
