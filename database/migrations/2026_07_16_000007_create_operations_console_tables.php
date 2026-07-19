<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('universe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('funding_mode', 16);
            $table->string('status', 16)->default('active');
            $table->string('currency', 3)->default('USD');
            $table->decimal('opening_cash', 20, 8);
            $table->decimal('reserved_cash', 20, 8)->default(0);
            $table->string('fee_scenario', 64);
            $table->string('slippage_scenario', 64);
            $table->timestamp('valuation_at');
            $table->unsignedBigInteger('source_account_snapshot_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['broker_account_id', 'status'], 'paper_sessions_account_status_idx');
            $table->index(['strategy_version_id', 'universe_version_id'], 'paper_sessions_versions_idx');
        });

        Schema::create('pipeline_cycles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('paper_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 16);
            $table->string('trigger', 24);
            $table->string('status', 24)->default('queued');
            $table->string('cycle_key', 128)->unique();
            $table->timestamp('as_of');
            $table->string('current_step', 48)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('summary_json')->nullable();
            $table->timestamps();

            $table->index(['broker_account_id', 'mode', 'status'], 'pipeline_cycles_active_idx');
            $table->index(['status', 'lease_expires_at'], 'pipeline_cycles_lease_idx');
            $table->index(['as_of', 'created_at'], 'pipeline_cycles_asof_idx');
        });

        Schema::create('pipeline_cycle_steps', function (Blueprint $table): void {
            $table->id();
            $table->uuid('pipeline_cycle_id');
            $table->foreign('pipeline_cycle_id')->references('id')->on('pipeline_cycles')->cascadeOnDelete();
            $table->string('step_key', 48);
            $table->unsignedSmallInteger('position');
            $table->string('status', 24)->default('queued');
            $table->uuid('engine_job_id')->nullable();
            $table->foreign('engine_job_id')->references('id')->on('engine_jobs')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('reason')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_cycle_id', 'step_key'], 'pipeline_cycle_steps_unique_step');
            $table->index(['status', 'lease_expires_at'], 'pipeline_cycle_steps_lease_idx');
        });

        Schema::create('asset_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('pipeline_cycle_id')->nullable();
            $table->foreign('pipeline_cycle_id')->references('id')->on('pipeline_cycles')->nullOnDelete();
            $table->foreignId('broker_account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('strategy_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('strategy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('universe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('engine_result_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 16);
            $table->string('evaluation_kind', 16)->default('trading');
            $table->timestamp('logical_bar_close');
            $table->timestamp('as_of');
            $table->boolean('eligible')->default(false);
            $table->string('action', 16)->default('HOLD');
            $table->decimal('score', 12, 6)->nullable();
            $table->decimal('calibrated_probability', 12, 8)->nullable();
            $table->decimal('expected_value_bps', 14, 6)->nullable();
            $table->text('primary_explanation');
            $table->jsonb('thresholds_json')->nullable();
            $table->jsonb('factor_attribution_json')->nullable();
            $table->jsonb('reason_codes_json')->nullable();
            $table->jsonb('warnings_json')->nullable();
            $table->jsonb('candle_evidence_json')->nullable();
            $table->timestamps();

            $table->index(['broker_account_id', 'logical_bar_close'], 'asset_evaluations_account_bar_idx');
            $table->index(['asset_id', 'created_at'], 'asset_evaluations_asset_created_idx');
            $table->index(['action', 'eligible', 'created_at'], 'asset_evaluations_action_idx');
        });

        Schema::table('trade_decisions', function (Blueprint $table): void {
            $table->foreignId('asset_evaluation_id')->nullable()->after('strategy_run_id')->constrained()->nullOnDelete();
            $table->index(['asset_evaluation_id', 'status'], 'trade_decisions_evaluation_status_idx');
        });

        Schema::create('activity_events', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 48);
            $table->string('source_id', 64);
            $table->string('event_type', 48);
            $table->string('category', 24);
            $table->string('severity', 16)->default('info');
            $table->string('title');
            $table->text('explanation');
            $table->timestamp('occurred_at');
            $table->foreignId('broker_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('pipeline_cycle_id')->nullable();
            $table->foreign('pipeline_cycle_id')->references('id')->on('pipeline_cycles')->nullOnDelete();
            $table->jsonb('detail_json')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'event_type'], 'activity_events_source_unique');
            $table->index(['occurred_at', 'category'], 'activity_events_time_category_idx');
            $table->index(['broker_account_id', 'occurred_at'], 'activity_events_account_time_idx');
        });

        Schema::create('paper_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reverses_entry_id')->nullable()->constrained('paper_ledger_entries')->nullOnDelete();
            $table->string('entry_type', 32);
            $table->string('fill_id', 80)->nullable();
            $table->decimal('cash_delta', 20, 8)->default(0);
            $table->decimal('reserved_cash_delta', 20, 8)->default(0);
            $table->decimal('quantity_delta', 24, 12)->default(0);
            $table->decimal('unit_price', 20, 8)->nullable();
            $table->decimal('fee', 20, 8)->default(0);
            $table->timestamp('occurred_at');
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->index(['paper_session_id', 'occurred_at'], 'paper_ledger_session_time_idx');
            $table->index(['broker_order_id', 'entry_type'], 'paper_ledger_order_type_idx');
        });

        Schema::create('operator_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('action_type', 48);
            $table->string('actor', 64)->default('local-operator');
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 128)->unique();
            $table->string('target_type', 48)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->jsonb('request_json')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'operator_actions_status_created_idx');
        });

        Schema::create('runtime_processes', function (Blueprint $table): void {
            $table->string('name', 48)->primary();
            $table->string('desired_state', 16)->default('running');
            $table->string('observed_state', 16)->default('unknown');
            $table->string('version', 64)->nullable();
            $table->string('current_task')->nullable();
            $table->string('process_identity', 128)->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['observed_state', 'heartbeat_at'], 'runtime_processes_state_heartbeat_idx');
        });

        Schema::create('runtime_control_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('operator_action_id')->constrained('operator_actions')->cascadeOnDelete();
            $table->string('process_name', 48);
            $table->foreign('process_name')->references('name')->on('runtime_processes')->cascadeOnDelete();
            $table->string('requested_action', 16);
            $table->string('status', 24)->default('queued');
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->timestamps();

            $table->index(['process_name', 'status', 'requested_at'], 'runtime_controls_process_status_idx');
        });

        Schema::table('paper_positions', function (Blueprint $table): void {
            $table->dropUnique('paper_positions_unique_account_asset');
            $table->foreignId('paper_session_id')->nullable()->after('broker_account_id')->constrained()->nullOnDelete();
            $table->unique(['paper_session_id', 'asset_id'], 'paper_positions_unique_session_asset');
            $table->index(['broker_account_id', 'asset_id'], 'paper_positions_account_asset_idx');
        });

        Schema::table('paper_order_events', function (Blueprint $table): void {
            $table->foreignId('paper_session_id')->nullable()->after('broker_account_id')->constrained()->nullOnDelete();
            $table->string('fill_id', 80)->nullable()->unique();
            $table->decimal('fee', 20, 8)->default(0);
        });

        Schema::table('paper_portfolio_snapshots', function (Blueprint $table): void {
            $table->foreignId('paper_session_id')->nullable()->after('broker_account_id')->constrained()->nullOnDelete();
            $table->index(['paper_session_id', 'snapshot_time'], 'paper_snapshots_session_time_idx');
        });

        Schema::table('broker_orders', function (Blueprint $table): void {
            $table->foreignId('paper_session_id')->nullable()->after('broker_account_id')->constrained()->nullOnDelete();
            $table->decimal('fee_amount', 20, 8)->default(0);
        });

        Schema::table('trade_attributions', function (Blueprint $table): void {
            $table->foreignId('paper_session_id')->nullable()->after('broker_order_id')->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX paper_sessions_one_active_per_account ON paper_sessions (broker_account_id) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX pipeline_cycles_one_active_per_account_mode ON pipeline_cycles (broker_account_id, mode) WHERE status IN ('queued', 'running', 'waiting_engine')");
            DB::statement("CREATE UNIQUE INDEX asset_evaluations_trading_bar_unique ON asset_evaluations (COALESCE(strategy_version_id, 0), COALESCE(universe_version_id, 0), broker_account_id, asset_id, logical_bar_close, mode) WHERE evaluation_kind = 'trading'");
            DB::statement('CREATE UNIQUE INDEX asset_evaluations_engine_asset_unique ON asset_evaluations (engine_result_id, asset_id) WHERE engine_result_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX paper_ledger_fill_type_unique ON paper_ledger_entries (paper_session_id, fill_id, entry_type) WHERE fill_id IS NOT NULL');
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_operations_append_only_mutation()
                RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'append-only operations records cannot be modified';
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER activity_events_append_only BEFORE UPDATE OR DELETE ON activity_events FOR EACH ROW EXECUTE FUNCTION reject_operations_append_only_mutation()');
            DB::statement('CREATE TRIGGER paper_ledger_append_only BEFORE UPDATE OR DELETE ON paper_ledger_entries FOR EACH ROW EXECUTE FUNCTION reject_operations_append_only_mutation()');
        } else {
            DB::statement("CREATE UNIQUE INDEX paper_sessions_one_active_per_account ON paper_sessions (broker_account_id) WHERE status = 'active'");
            DB::statement("CREATE UNIQUE INDEX pipeline_cycles_one_active_per_account_mode ON pipeline_cycles (broker_account_id, mode) WHERE status IN ('queued', 'running', 'waiting_engine')");
            DB::statement("CREATE UNIQUE INDEX asset_evaluations_trading_bar_unique ON asset_evaluations (COALESCE(strategy_version_id, 0), COALESCE(universe_version_id, 0), broker_account_id, asset_id, logical_bar_close, mode) WHERE evaluation_kind = 'trading'");
            DB::statement('CREATE UNIQUE INDEX asset_evaluations_engine_asset_unique ON asset_evaluations (engine_result_id, asset_id) WHERE engine_result_id IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX paper_ledger_fill_type_unique ON paper_ledger_entries (paper_session_id, fill_id, entry_type) WHERE fill_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('trade_attributions', fn (Blueprint $table) => $table->dropConstrainedForeignId('paper_session_id'));
        Schema::table('broker_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paper_session_id');
            $table->dropColumn('fee_amount');
        });
        Schema::table('paper_portfolio_snapshots', function (Blueprint $table): void {
            $table->dropIndex('paper_snapshots_session_time_idx');
            $table->dropConstrainedForeignId('paper_session_id');
        });
        Schema::table('paper_order_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('paper_session_id');
            $table->dropUnique(['fill_id']);
            $table->dropColumn(['fill_id', 'fee']);
        });
        Schema::table('paper_positions', function (Blueprint $table): void {
            $table->dropUnique('paper_positions_unique_session_asset');
            $table->dropIndex('paper_positions_account_asset_idx');
            $table->dropConstrainedForeignId('paper_session_id');
            $table->unique(['broker_account_id', 'asset_id'], 'paper_positions_unique_account_asset');
        });
        Schema::dropIfExists('runtime_control_requests');
        Schema::dropIfExists('runtime_processes');
        Schema::dropIfExists('operator_actions');
        Schema::dropIfExists('paper_ledger_entries');
        Schema::dropIfExists('activity_events');
        Schema::table('trade_decisions', fn (Blueprint $table) => $table->dropConstrainedForeignId('asset_evaluation_id'));
        Schema::dropIfExists('asset_evaluations');
        Schema::dropIfExists('pipeline_cycle_steps');
        Schema::dropIfExists('pipeline_cycles');
        Schema::dropIfExists('paper_sessions');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_operations_append_only_mutation()');
        }
    }
};
