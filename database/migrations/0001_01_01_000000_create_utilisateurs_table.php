<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('Utilisateurs', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('prenom');
            $table->string('username_outil_cicd')->unique()->comment("Nom d'utilisateur GitHub");
            $table->string('mot_de_passe');
            $table->string('api_token', 64)->unique()->nullable();
            $table->string('token_outil_cicd')->nullable()->comment('Token GitHub fourni à la connexion');
            $table->timestamp('date_inscription')->nullable()->comment("Date d'inscription sur la plateforme");
            $table->enum('role', ['administrateur', 'administrateur_cloud_doi', 'securite'])->default('securite');
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('Utilisateurs');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
