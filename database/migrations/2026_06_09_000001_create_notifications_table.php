<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('utilisateur_id')
                  ->constrained('Utilisateurs')
                  ->cascadeOnDelete()
                  ->comment('Destinataire de la notification');
            $table->string('titre', 255);
            $table->text('message');
            $table->enum('type', ['info', 'succes', 'attention', 'erreur'])
                  ->default('info');
            $table->boolean('est_lu')->default(false);
            $table->timestamp('date_creation')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
