<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_strategy_experiments', function (Blueprint $t) {
            $t->id();
            $t->uuid('experiment_id')->unique();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('scope', 64);
            $t->uuid('request_id');
            $t->string('definition_hash', 64);
            $t->text('definition');
            $t->string('status', 16);
            $t->string('reason', 64)->nullable();
            $t->string('current_step', 64)->nullable();
            $t->unsignedSmallInteger('step_attempt')->default(0);
            $t->unsignedSmallInteger('attempt_limit')->default(3);
            $t->uuid('lease_token')->nullable();
            $t->timestampTz('lease_expires_at', 6)->nullable();
            $t->timestampTz('available_at', 6)->nullable();
            $t->text('report')->nullable();
            $t->timestampsTz(6);
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'strategy_experiment_request');
        });
        Schema::create('mcp_strategy_experiment_steps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('experiment_id')->constrained('mcp_strategy_experiments');
            $t->string('step_key', 64);
            $t->string('completed_key', 64)->nullable();
            $t->unsignedSmallInteger('attempt');
            $t->string('status', 16);
            $t->string('reason', 64)->nullable();
            $t->string('fingerprint', 64);
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['experiment_id', 'step_key', 'attempt'], 'strategy_experiment_attempt');
            $t->unique(['experiment_id', 'completed_key'], 'strategy_experiment_checkpoint');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_experiments" ADD CONSTRAINT strategy_experiment_state CHECK (status IN (\'queued\',\'running\',\'paused\',\'failed\',\'complete\') AND ((lease_token IS NULL) = (lease_expires_at IS NULL)) AND ((status = \'complete\') = (report IS NOT NULL)))');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_experiment_steps" ADD CONSTRAINT strategy_experiment_step_state CHECK (status IN (\'complete\',\'failed\') AND attempt > 0 AND ((status = \'complete\' AND completed_key IS NOT NULL AND completed_key = step_key) OR (status = \'failed\' AND completed_key IS NULL)))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_strategy_experiment_steps');
        Schema::dropIfExists('mcp_strategy_experiments');
    }
};
