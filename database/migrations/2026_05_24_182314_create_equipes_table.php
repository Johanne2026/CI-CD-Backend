<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Equipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proprietaire_id')
                  ->constrained('Utilisateurs')
                  ->cascadeOnDelete();
            $table->string('nom');
            $table->text('description')->nullable();
            $table->timestamp('date_creation')->useCurrent();
            $table->timestamp('date_mise_a_jour')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Equipes');
    }
};
