<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deploiements', function (Blueprint $table) {
            // Logs CI compilés en texte brut (symétrique à la colonne "logs" qui contient les logs CD)
            $table->longText('logs_ci')->nullable()->after('logs');
        });
    }

    public function down(): void
    {
        Schema::table('deploiements', function (Blueprint $table) {
            $table->dropColumn('logs_ci');
        });
    }
};
