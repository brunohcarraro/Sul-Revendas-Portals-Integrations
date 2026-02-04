<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_leads', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('veiculo_id')->nullable(); // FK to tb_veiculos
            $table->unsignedInteger('anunciante_id')->nullable(); // FK to tb_anunciantes
            $table->string('portal', 50); // webmotors, olx, mercadolivre
            $table->string('external_lead_id')->nullable(); // ID on the portal
            $table->string('external_ad_id')->nullable(); // Ad ID on the portal

            // Contact info
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();

            // Extra data from portal
            $table->json('extra_data')->nullable();

            // Status tracking
            $table->enum('status', [
                'new',
                'contacted',
                'negotiating',
                'converted',
                'lost',
                'spam'
            ])->default('new');

            $table->timestamp('received_at')->nullable(); // when portal sent it
            $table->timestamps();

            $table->index(['portal', 'external_lead_id']);
            $table->index('veiculo_id');
            $table->index('anunciante_id');
            $table->index(['portal', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_leads');
    }
};
