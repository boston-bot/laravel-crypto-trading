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
        Schema::create('market_candles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('symbol');
            $table->string('timeframe', 8);
            $table->timestamp('candle_open_time');
            $table->timestamp('candle_close_time')->nullable();
            $table->decimal('open', 20, 8);
            $table->decimal('high', 20, 8);
            $table->decimal('low', 20, 8);
            $table->decimal('close', 20, 8);
            $table->decimal('volume', 28, 12)->nullable();
            $table->decimal('turnover_usd', 24, 8)->nullable();
            $table->string('source')->default('robinhood');
            $table->timestamp('ingested_at')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'timeframe', 'candle_open_time', 'source'], 'market_candles_unique_point');
            $table->index(['asset_id', 'timeframe', 'candle_open_time'], 'market_candles_asset_tf_open_idx');
            $table->index(['symbol', 'timeframe', 'candle_open_time'], 'market_candles_symbol_tf_open_idx');
        });

        Schema::create('market_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamp('snapshot_time');
            $table->decimal('bid_price', 20, 8)->nullable();
            $table->decimal('ask_price', 20, 8)->nullable();
            $table->decimal('mid_price', 20, 8)->nullable();
            $table->decimal('last_price', 20, 8)->nullable();
            $table->decimal('spread_bps', 10, 4)->nullable();
            $table->decimal('liquidity_score', 8, 4)->nullable();
            $table->decimal('slippage_bps_estimate', 10, 4)->nullable();
            $table->string('source')->default('robinhood');
            $table->jsonb('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'snapshot_time', 'source'], 'market_quotes_unique_point');
            $table->index(['asset_id', 'snapshot_time'], 'market_quotes_asset_snapshot_idx');
        });

        Schema::create('asset_feature_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamp('snapshot_time');
            $table->string('timeframe', 8)->default('1d');
            $table->decimal('trend_score', 8, 4)->default(0);
            $table->decimal('momentum_score', 8, 4)->default(0);
            $table->decimal('relative_strength_score', 8, 4)->default(0);
            $table->decimal('pullback_quality_score', 8, 4)->default(0);
            $table->decimal('volatility_quality_score', 8, 4)->default(0);
            $table->decimal('participation_score', 8, 4)->default(0);
            $table->decimal('execution_quality_penalty', 8, 4)->default(0);
            $table->decimal('atr_pct', 12, 6)->nullable();
            $table->decimal('realized_volatility_20d', 12, 6)->nullable();
            $table->jsonb('features_json')->nullable();
            $table->string('source')->default('strategy-v1');
            $table->timestamps();

            $table->unique(['asset_id', 'timeframe', 'snapshot_time'], 'asset_feature_snapshots_unique_point');
            $table->index(['asset_id', 'snapshot_time'], 'asset_feature_snapshots_asset_snapshot_idx');
            $table->index(['snapshot_time'], 'asset_feature_snapshots_snapshot_idx');
        });

        Schema::create('market_regime_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('snapshot_time');
            $table->string('regime', 16);
            $table->decimal('confidence', 8, 4)->default(0);
            $table->jsonb('components_json')->nullable();
            $table->string('source')->default('strategy-v1');
            $table->timestamps();

            $table->unique(['snapshot_time', 'source'], 'market_regime_snapshots_unique_point');
            $table->index(['regime', 'snapshot_time'], 'market_regime_snapshots_regime_snapshot_idx');
        });

        Schema::create('strategy_parameters', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy_name');
            $table->string('version');
            $table->boolean('is_active')->default(true);
            $table->jsonb('parameters_json');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->unique(['strategy_name', 'version'], 'strategy_parameters_unique_key');
            $table->index(['strategy_name', 'is_active'], 'strategy_parameters_active_idx');
        });

        Schema::create('backtest_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('strategy_name');
            $table->foreignId('strategy_parameter_id')->nullable()->constrained('strategy_parameters')->nullOnDelete();
            $table->timestamp('run_started_at');
            $table->timestamp('run_completed_at')->nullable();
            $table->timestamp('timeframe_start')->nullable();
            $table->timestamp('timeframe_end')->nullable();
            $table->string('status')->default('running');
            $table->string('trigger')->default('manual');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['strategy_name', 'run_started_at'], 'backtest_runs_strategy_started_idx');
            $table->index(['status', 'run_started_at'], 'backtest_runs_status_started_idx');
        });

        Schema::create('backtest_run_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('backtest_run_id')->constrained()->cascadeOnDelete();
            $table->string('metric_name');
            $table->string('metric_group')->default('portfolio');
            $table->decimal('metric_value', 24, 8);
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->unique(['backtest_run_id', 'metric_name', 'metric_group'], 'backtest_run_metrics_unique_metric');
            $table->index(['metric_name', 'metric_group'], 'backtest_run_metrics_metric_idx');
        });

        Schema::create('trade_attributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('expected_probability', 8, 4)->nullable();
            $table->decimal('expected_expectancy', 16, 8)->nullable();
            $table->decimal('realized_return_pct', 16, 8)->nullable();
            $table->decimal('realized_pnl', 20, 8)->nullable();
            $table->decimal('mae_pct', 16, 8)->nullable();
            $table->decimal('mfe_pct', 16, 8)->nullable();
            $table->unsignedInteger('hold_hours')->nullable();
            $table->timestamp('attributed_at');
            $table->jsonb('attribution_json')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'attributed_at'], 'trade_attributions_asset_attributed_idx');
            $table->index(['trade_decision_id'], 'trade_attributions_decision_idx');
        });

        Schema::create('paper_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 24, 12)->default(0);
            $table->decimal('avg_entry_price', 20, 8)->nullable();
            $table->decimal('cost_basis', 20, 8)->default(0);
            $table->decimal('market_price', 20, 8)->nullable();
            $table->decimal('market_value', 20, 8)->default(0);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('updated_snapshot_at');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['broker_account_id', 'asset_id'], 'paper_positions_unique_account_asset');
        });

        Schema::create('paper_order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('status');
            $table->string('side')->nullable();
            $table->timestamp('event_time');
            $table->decimal('quantity', 24, 12)->nullable();
            $table->decimal('notional', 20, 8)->nullable();
            $table->decimal('reference_price', 20, 8)->nullable();
            $table->decimal('fill_price', 20, 8)->nullable();
            $table->decimal('slippage_bps', 10, 4)->nullable();
            $table->jsonb('payload_json')->nullable();
            $table->timestamps();

            $table->index(['broker_account_id', 'event_time'], 'paper_order_events_account_event_idx');
            $table->index(['trade_decision_id', 'event_time'], 'paper_order_events_decision_event_idx');
        });

        Schema::create('paper_portfolio_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->timestamp('snapshot_time');
            $table->decimal('equity', 20, 8);
            $table->decimal('cash', 20, 8);
            $table->decimal('invested_value', 20, 8)->default(0);
            $table->decimal('realized_pnl', 20, 8)->default(0);
            $table->decimal('unrealized_pnl', 20, 8)->default(0);
            $table->decimal('gross_exposure_pct', 8, 4)->default(0);
            $table->decimal('heat_score', 8, 4)->default(0);
            $table->decimal('drawdown_pct', 8, 4)->default(0);
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['broker_account_id', 'snapshot_time'], 'paper_portfolio_snapshots_unique_point');
            $table->index(['snapshot_time'], 'paper_portfolio_snapshots_snapshot_idx');
        });

        Schema::create('execution_quality_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->timestamp('snapshot_time');
            $table->decimal('spread_bps', 10, 4)->nullable();
            $table->decimal('slippage_bps_estimate', 10, 4)->nullable();
            $table->decimal('liquidity_score', 8, 4)->nullable();
            $table->decimal('execution_penalty_score', 8, 4)->default(0);
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'snapshot_time'], 'execution_quality_snapshots_unique_point');
            $table->index(['asset_id', 'snapshot_time'], 'execution_quality_snapshots_asset_snapshot_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('execution_quality_snapshots');
        Schema::dropIfExists('paper_portfolio_snapshots');
        Schema::dropIfExists('paper_order_events');
        Schema::dropIfExists('paper_positions');
        Schema::dropIfExists('trade_attributions');
        Schema::dropIfExists('backtest_run_metrics');
        Schema::dropIfExists('backtest_runs');
        Schema::dropIfExists('strategy_parameters');
        Schema::dropIfExists('market_regime_snapshots');
        Schema::dropIfExists('asset_feature_snapshots');
        Schema::dropIfExists('market_quotes');
        Schema::dropIfExists('market_candles');
    }
};
