<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sync_id')->nullable(); // FK to vehicle_portal_sync
            $table->unsignedInteger('veiculo_id')->nullable();
            $table->string('portal', 50);
            $table->string('action', 50); // create, update, remove, fetch_leads, etc.
            $table->enum('result', ['success', 'error', 'skipped']);
            $table->string('http_method')->nullable();
            $table->string('endpoint')->nullable();
            $table->integer('http_status')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_body')->nullable();
            $table->text('error_message')->nullable();
            $table->integer('duration_ms')->nullable(); // request duration
            $table->timestamp('created_at')->useCurrent();

            $table->index(['portal', 'action', 'created_at']);
            $table->index(['sync_id']);
            $table->index(['veiculo_id', 'portal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_sync_logs');
    }
};
