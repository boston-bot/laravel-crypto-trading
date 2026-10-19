<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_external_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->string('broker_id_hash', 64);
            $t->foreignId('snapshot_id')->constrained('mcp_snapshots');
            $t->unsignedInteger('auth_generation');
            $t->text('payload');
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('detected_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'broker_id_hash'], 'external_order_identity');
        });
        Schema::create('mcp_external_order_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('external_order_id')->unique()->constrained('mcp_external_orders');
            $t->foreignId('actor_id')->constrained('users');
            $t->uuid('request_id')->unique();
            $t->text('payload');
            $t->timestampTz('reviewed_at', 6);
        });
        Schema::create('mcp_broker_order_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->unsignedInteger('auth_generation');
            $t->foreignId('snapshot_id')->constrained('mcp_snapshots');
            $t->uuid('read_claim');
            $t->unique(['snapshot_id', 'read_claim'], 'broker_order_read_attempt');
            $t->boolean('complete');
            $t->text('payload');
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->index(['mcp_connection_id', 'scope', 'id'], 'broker_order_read_history');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_broker_order_reads');
        Schema::dropIfExists('mcp_external_order_reviews');
        Schema::dropIfExists('mcp_external_orders');
    }
};
