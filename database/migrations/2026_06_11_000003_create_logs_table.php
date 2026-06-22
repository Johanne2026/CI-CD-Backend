<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('deploiement_id')
                  ->nullable()
                  ->comment('Référence vers deploiements.id');
            $table->enum('source', ['CI', 'CD'])
                  ->comment('CI = GitHub Actions, CD = deploy.ps1 sur la VM');
            $table->enum('niveau', ['INFO', 'WARNING', 'ERROR'])
                  ->default('INFO');
            $table->string('etape', 100)
                  ->nullable()
                  ->comment('Ex: BUILD, TEST, PACKAGE, DEPLOY, VALIDATE...');
            $table->text('message');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['deploiement_id', 'source']);
            $table->index('etape');

            $table->foreign('deploiement_id')
                  ->references('id')
                  ->on('deploiements')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('logs');
    }
};
