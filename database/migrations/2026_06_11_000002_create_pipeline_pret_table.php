<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_pret', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projet_id')
                  ->constrained('Projets')
                  ->cascadeOnDelete();
            $table->unsignedBigInteger('run_id')
                  ->comment('ID du run GitHub Actions CI marqué prêt');
            $table->string('branche', 255)->nullable();
            $table->string('commit_sha', 40)->nullable();
            $table->string('nom_workflow', 255)->nullable();
            $table->foreignId('marque_par')
                  ->constrained('Utilisateurs')
                  ->restrictOnDelete()
                  ->comment('Administrateur qui a marqué le run prêt');
            $table->boolean('deploye')->default(false)
                  ->comment('true une fois que le Cloud DOI a lancé le déploiement');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_pret');
    }
};
