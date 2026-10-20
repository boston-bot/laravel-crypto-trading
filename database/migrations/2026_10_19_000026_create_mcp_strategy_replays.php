<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_strategy_replays', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->foreignId('actor_id')->constrained('users');
            $t->string('replay_version', 32);
            $t->string('profile_version', 32);
            $t->string('rule_version', 32);
            $t->string('parameters_hash', 64);
            $t->string('status', 16);
            $t->string('reason', 64);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('input_fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'strategy_replay_request');
            $t->index(['mcp_connection_id', 'scope', 'recorded_at'], 'strategy_replay_history');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_replays" ADD CONSTRAINT strategy_replay_states CHECK (status IN (\'complete\',\'incomplete\') AND ((status = \'complete\') = (start_date IS NOT NULL AND end_date IS NOT NULL)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_strategy_replays');
    }
};
