<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_portal_sync', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('veiculo_id'); // FK to tb_veiculos
            $table->string('portal', 50); // webmotors, olx, mercadolivre
            $table->string('external_id')->nullable(); // ID on the portal
            $table->string('external_url')->nullable(); // URL of the ad on the portal
            $table->enum('status', [
                'pending',      // waiting to be published
                'publishing',   // currently being published
                'published',    // successfully published
                'updating',     // being updated
                'removing',     // being removed
                'removed',      // successfully removed
                'error',        // last operation failed
                'paused'        // manually paused
            ])->default('pending');
            $table->enum('last_action', [
                'create',
                'update',
                'remove',
                'pause',
                'activate'
            ])->nullable();
            $table->text('last_error')->nullable();
            $table->json('last_payload')->nullable(); // last sent payload for debugging
            $table->json('last_response')->nullable(); // last portal response
            $table->string('content_hash')->nullable(); // hash to detect changes
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['veiculo_id', 'portal']);
            $table->index(['portal', 'status']);
            $table->index('veiculo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_portal_sync');
    }
};
