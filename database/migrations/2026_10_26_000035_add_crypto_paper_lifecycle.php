<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Crypto paper orders pin an instrument policy instead of an equity policy application. Existing rows are unchanged.
        Schema::table('mcp_paper_sim_orders', function (Blueprint $t) {
            $t->unsignedBigInteger('policy_application_id')->nullable()->change();
            $t->foreignId('instrument_policy_id')->nullable()->constrained('mcp_paper_instrument_policies');
            $t->string('symbol', 24)->change();
        });
        Schema::table('mcp_paper_sim_positions', fn (Blueprint $t) => $t->string('symbol', 24)->change());
        // Immutable account fee-tier observations; each is valid for at most one day and never becomes a default.
        Schema::create('mcp_crypto_fee_observations', function (Blueprint $t) {
            $t->id();
            $t->string('venue', 16);
            $t->foreignId('actor_id')->constrained('users');
            $t->string('trigger', 16);
            $t->string('status', 16);
            $t->string('contract', 64)->nullable();
            $t->string('pricing_tier', 128)->nullable();
            $t->decimal('maker_rate', 20, 18)->nullable();
            $t->decimal('taker_rate', 20, 18)->nullable();
            $t->text('reasons');
            $t->string('response_sha256', 64)->nullable();
            $t->timestampTz('request_started_at', 6);
            $t->timestampTz('retrieved_at', 6)->nullable();
            $t->timestampTz('recorded_at', 6);
            $t->index(['venue', 'status', 'retrieved_at']);
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE "mcp_trading"."mcp_paper_sim_orders" ADD CONSTRAINT sim_order_policy_source CHECK ((policy_application_id IS NOT NULL) <> (instrument_policy_id IS NOT NULL))');
            DB::statement('ALTER TABLE "mcp_trading"."mcp_crypto_fee_observations" ADD CONSTRAINT crypto_fee_observation_state CHECK ((status = \'complete\' AND pricing_tier IS NOT NULL AND maker_rate >= 0 AND maker_rate <= 1 AND taker_rate >= 0 AND taker_rate <= 1 AND retrieved_at IS NOT NULL AND response_sha256 IS NOT NULL) OR (status = \'failed\' AND pricing_tier IS NULL AND maker_rate IS NULL AND taker_rate IS NULL))');
        }
    }

    public function down(): void
    {
        // Restoring NOT NULL would require deleting legitimate crypto paper history. Never automate that rollback.
        throw new RuntimeException('Forward-only crypto paper migration; restore a verified backup or use a reviewed forward migration.');
    }
};
