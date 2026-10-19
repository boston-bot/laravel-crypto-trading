<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_market_reference_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('symbol', 16);
            $t->string('kind', 16);
            $t->string('provider', 96);
            $t->string('contract', 64);
            $t->string('status', 16);
            $t->string('reason', 64)->nullable();
            $t->text('payload');
            $t->timestampTz('source_at', 6)->nullable();
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'market_reference_request');
            $t->index(['mcp_connection_id', 'scope', 'symbol', 'kind', 'recorded_at'], 'market_reference_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_market_reference_reads" ADD CONSTRAINT market_reference_states CHECK (kind IN (\'daily_history\',\'listing\') AND status IN (\'complete\',\'incomplete\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_market_reference_reads');
    }
};
