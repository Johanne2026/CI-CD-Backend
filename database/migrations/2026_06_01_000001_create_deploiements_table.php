<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deploiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')->constrained('Projets');
            $table->foreignId('lance_par')->constrained('Utilisateurs');
            $table->string('app', 255);
            $table->string('version', 255)->default('latest');
            $table->enum('statut', ['en_attente', 'en_cours', 'termine', 'echoue'])
                  ->default('en_attente');
            $table->longText('logs')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deploiements');
    }
};
