<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploiements', function (Blueprint $table) {
            $table->unsignedBigInteger('github_run_id')
                  ->nullable()
                  ->after('version')
                  ->comment('ID du run GitHub Actions CI correspondant à ce déploiement CD');
        });
    }

    public function down(): void
    {
        Schema::table('deploiements', function (Blueprint $table) {
            $table->dropColumn('github_run_id');
        });
    }
};
