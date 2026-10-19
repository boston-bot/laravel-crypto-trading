<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_symbol_profiles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('symbol', 16);
            $t->date('session_date')->nullable();
            $t->string('profile_version', 32);
            $t->string('parameters_version', 48);
            $t->foreignId('history_read_id')->nullable()->constrained('mcp_market_reference_reads');
            $t->foreignId('benchmark_read_id')->nullable()->constrained('mcp_market_reference_reads');
            $t->string('status', 16);
            $t->string('setup', 16)->nullable();
            $t->string('reason', 64);
            $t->string('input_fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('cutoff', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'symbol_profile_request');
            $t->index(['mcp_connection_id', 'scope', 'symbol', 'recorded_at'], 'symbol_profile_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_symbol_profiles" ADD CONSTRAINT symbol_profile_states CHECK ((status = \'complete\' AND setup IN (\'breakout\',\'pullback\',\'none\')) OR (status = \'unavailable\' AND setup IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_symbol_profiles');
    }
};
