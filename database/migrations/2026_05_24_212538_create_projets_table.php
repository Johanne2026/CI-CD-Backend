<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Projets', function (Blueprint $table) {
            $table->id();

            // Une équipe a un seul projet — contrainte unique sur equipe_id
            $table->foreignId('equipe_id')
                  ->unique()
                  ->constrained('Equipes')
                  ->cascadeOnDelete();

            $table->foreignId('cree_par_id')
                  ->constrained('Utilisateurs')
                  ->restrictOnDelete();

            $table->string('nom');
            $table->text('description')->nullable();

            // Liste des technologies stockée en JSON
            $table->json('stack_technologique')->nullable()->comment('Liste des technologies utilisées');

            $table->boolean('actif')->default(true)->comment('true = actif, false = archivé');

            $table->string('duree_projet')->nullable()->comment('Ex: 3 mois, 6 semaines...');

            $table->timestamp('date_creation')->useCurrent();
            $table->timestamp('date_mise_a_jour')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Projets');
    }
};
