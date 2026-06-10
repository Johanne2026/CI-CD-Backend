<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->dropColumn('token_outil_cicd');
        });
    }

    public function down(): void
    {
        Schema::table('Utilisateurs', function (Blueprint $table) {
            $table->string('token_outil_cicd')
                  ->nullable()
                  ->comment('Token GitHub — migré vers GITHUB_TOKEN dans .env');
        });
    }
};
