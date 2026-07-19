<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pipeline_cycles', function (Blueprint $table): void {
            $table->foreignId('strategy_version_id')->nullable()->after('paper_session_id')->constrained()->nullOnDelete();
            $table->foreignId('universe_version_id')->nullable()->after('strategy_version_id')->constrained()->nullOnDelete();
            $table->string('evaluation_kind', 16)->default('trading')->after('trigger');
            $table->timestamp('logical_bar_close')->nullable()->after('as_of');
            $table->timestamp('evidence_cutoff')->nullable()->after('logical_bar_close');

            $table->index(['logical_bar_close', 'evaluation_kind'], 'pipeline_cycles_logical_bar_idx');
        });

        Schema::table('asset_evaluations', function (Blueprint $table): void {
            $table->boolean('actionable')->default(false)->after('eligible');
            $table->timestamp('action_suppressed_at')->nullable()->after('actionable');
            $table->string('action_suppression_reason', 64)->nullable()->after('action_suppressed_at');

            $table->index(['actionable', 'action_suppression_reason', 'created_at'], 'asset_evaluations_actionability_idx');
        });

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->index(
                ['asset_id', 'source', 'timeframe', 'is_final', 'quality_state', 'candle_close_time', 'available_at', 'first_seen_at'],
                'market_candles_common_evidence_idx',
            );
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX pipeline_cycles_logical_identity_unique
            ON pipeline_cycles (
                broker_account_id,
                mode,
                evaluation_kind,
                COALESCE(strategy_version_id, 0),
                COALESCE(universe_version_id, 0),
                logical_bar_close
            )
            WHERE logical_bar_close IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pipeline_cycles_logical_identity_unique');

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->dropIndex('market_candles_common_evidence_idx');
        });

        Schema::table('asset_evaluations', function (Blueprint $table): void {
            $table->dropIndex('asset_evaluations_actionability_idx');
            $table->dropColumn(['actionable', 'action_suppressed_at', 'action_suppression_reason']);
        });

        Schema::table('pipeline_cycles', function (Blueprint $table): void {
            $table->dropIndex('pipeline_cycles_logical_bar_idx');
            $table->dropConstrainedForeignId('universe_version_id');
            $table->dropConstrainedForeignId('strategy_version_id');
            $table->dropColumn(['evaluation_kind', 'logical_bar_close', 'evidence_cutoff']);
        });
    }
};
