<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membre_equipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')
                  ->constrained('Utilisateurs')
                  ->cascadeOnDelete();
            $table->foreignId('equipe_id')
                  ->constrained('Equipes')
                  ->cascadeOnDelete();
            $table->enum('role', ['proprietaire', 'membre'])->default('membre');
            $table->timestamp('date_adhesion')->useCurrent();

            // Un utilisateur ne peut appartenir qu'une seule fois à une équipe
            $table->unique(['utilisateur_id', 'equipe_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membre_equipe');
    }
};
