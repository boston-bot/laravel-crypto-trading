<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_strategy_watchlist_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_connection_id')->constrained();
            $t->string('scope', 64);
            $t->foreignId('actor_id')->constrained('users');
            $t->unsignedInteger('version');
            $t->uuid('request_id');
            $t->text('payload');
            $t->timestampTz('recorded_at', 6);
            $t->unique(['mcp_connection_id', 'scope', 'version'], 'strategy_watchlist_setting_version');
            $t->unique(['mcp_connection_id', 'scope', 'request_id'], 'strategy_watchlist_setting_request');
        });
        Schema::create('mcp_strategy_watchlist_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('setting_id')->constrained('mcp_strategy_watchlist_settings');
            $t->unsignedInteger('auth_generation');
            $t->uuid('request_id');
            $t->string('membership_hash', 64);
            $t->text('payload');
            $t->timestampTz('retrieved_at', 6);
            $t->timestampTz('changed_at', 6);
            $t->timestampTz('recorded_at', 6);
            $t->unique(['setting_id', 'request_id'], 'strategy_watchlist_revision_request');
        });
        Schema::create('mcp_strategy_watchlist_reads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('setting_id')->constrained('mcp_strategy_watchlist_settings');
            $t->uuid('request_id');
            $t->foreignId('revision_id')->nullable()->constrained('mcp_strategy_watchlist_revisions');
            $t->string('reason', 64)->nullable();
            $t->timestampTz('recorded_at', 6);
            $t->unique(['setting_id', 'request_id'], 'strategy_watchlist_read_request');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_watchlist_reads" ADD CONSTRAINT strategy_watchlist_read_outcome CHECK ((revision_id IS NULL) <> (reason IS NULL))');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_strategy_watchlist_settings" ADD CONSTRAINT strategy_watchlist_positive_version CHECK (version > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_strategy_watchlist_reads');
        Schema::dropIfExists('mcp_strategy_watchlist_revisions');
        Schema::dropIfExists('mcp_strategy_watchlist_settings');
    }
};
