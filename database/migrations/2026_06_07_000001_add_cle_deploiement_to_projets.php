<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            $table->string('cle_deploiement', 64)
                  ->nullable()
                  ->unique()
                  ->after('url_depot')
                  ->comment('Clé de déploiement générée — stockée aussi dans GitHub Repository Secrets (DEPLOY_KEY)');
        });
    }

    public function down(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            $table->dropColumn('cle_deploiement');
        });
    }
};
