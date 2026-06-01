<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            $table->string('url_depot')->nullable()
                  ->comment('URL du dépôt GitHub lié au projet')
                  ->after('duree_projet');
        });
    }

    public function down(): void
    {
        Schema::table('Projets', function (Blueprint $table) {
            $table->dropColumn('url_depot');
        });
    }
};
