<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('portal', 50); // webmotors, olx, mercadolivre
            $table->unsignedInteger('anunciante_id')->nullable(); // FK to tb_anunciantes if per-dealer
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('extra_config')->nullable(); // portal-specific settings
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['portal', 'anunciante_id']);
            $table->index('portal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_credentials');
    }
};
