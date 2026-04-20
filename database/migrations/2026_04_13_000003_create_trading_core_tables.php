<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('broker_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('broker');
            $table->string('label');
            $table->string('api_key_ref');
            $table->string('secret_ref');
            $table->string('status')->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('broker_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_credential_id')->nullable()->constrained()->nullOnDelete();
            $table->string('broker');
            $table->string('external_account_id');
            $table->string('account_type')->nullable();
            $table->string('currency', 3)->default('USD');
            $table->decimal('buying_power', 20, 8)->default(0);
            $table->decimal('cash_balance', 20, 8)->default(0);
            $table->decimal('equity', 20, 8)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('snapshot_at');
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['broker', 'external_account_id']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->string('broker');
            $table->string('symbol');
            $table->string('asset_type')->default('crypto');
            $table->boolean('is_tradable')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->decimal('min_order_notional', 20, 8)->nullable();
            $table->unsignedTinyInteger('price_precision')->nullable();
            $table->unsignedTinyInteger('quantity_precision')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['broker', 'symbol']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 24, 12)->default(0);
            $table->decimal('avg_cost', 20, 8)->nullable();
            $table->decimal('market_value', 20, 8)->nullable();
            $table->decimal('unrealized_pnl', 20, 8)->nullable();
            $table->timestamp('snapshot_at');
            $table->json('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['broker_account_id', 'asset_id']);
        });

        Schema::create('strategy_runs', function (Blueprint $table) {
            $table->id();
            $table->string('strategy_name');
            $table->string('mode');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->decimal('account_equity', 20, 8)->nullable();
            $table->json('summary_json')->nullable();
            $table->json('skill_outputs_json')->nullable();
            $table->string('status')->default('running');
            $table->timestamps();
        });

        Schema::create('trade_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('decision');
            $table->string('side')->nullable();
            $table->decimal('score', 10, 4)->nullable();
            $table->decimal('confidence', 6, 4)->nullable();
            $table->decimal('requested_quantity', 24, 12)->nullable();
            $table->decimal('requested_notional', 20, 8)->nullable();
            $table->json('market_context_json')->nullable();
            $table->json('signal_context_json')->nullable();
            $table->json('risk_context_json')->nullable();
            $table->json('policy_result_json')->nullable();
            $table->boolean('requires_human_approval')->default(true);
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('status')->default('draft');
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('broker_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_id')->nullable()->index();
            $table->string('client_order_id')->nullable()->unique();
            $table->string('side');
            $table->string('order_type')->default('market');
            $table->string('time_in_force')->nullable();
            $table->decimal('requested_quantity', 24, 12)->nullable();
            $table->decimal('requested_notional', 20, 8)->nullable();
            $table->decimal('requested_price', 20, 8)->nullable();
            $table->string('status')->default('draft');
            $table->decimal('filled_quantity', 24, 12)->nullable();
            $table->decimal('filled_notional', 20, 8)->nullable();
            $table->decimal('avg_fill_price', 20, 8)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->json('raw_request_json')->nullable();
            $table->json('raw_response_json')->nullable();
            $table->timestamps();
        });

        Schema::create('risk_events', function (Blueprint $table) {
            $table->id();
            $table->string('severity');
            $table->string('event_type');
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message');
            $table->json('context_json')->nullable();
            $table->timestamp('triggered_at');
            $table->timestamps();
        });

        Schema::create('policy_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->string('policy_name');
            $table->boolean('result');
            $table->string('message')->nullable();
            $table->json('context_json')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });

        Schema::create('daily_portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->decimal('equity', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->decimal('invested_value', 20, 8)->default(0);
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('drawdown_pct', 8, 4)->default(0);
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['broker_account_id', 'snapshot_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_portfolio_snapshots');
        Schema::dropIfExists('policy_checks');
        Schema::dropIfExists('risk_events');
        Schema::dropIfExists('broker_orders');
        Schema::dropIfExists('trade_decisions');
        Schema::dropIfExists('strategy_runs');
        Schema::dropIfExists('positions');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('broker_accounts');
        Schema::dropIfExists('broker_credentials');
    }
};

