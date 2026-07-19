<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_candles', function (Blueprint $table): void {
            $table->timestamp('first_seen_at')->nullable()->after('ingested_at');
            $table->timestamp('available_at')->nullable()->after('first_seen_at');
            $table->boolean('is_final')->default(false)->after('available_at');
            $table->unsignedInteger('source_revision')->default(1)->after('is_final');
            $table->string('content_hash', 64)->nullable()->after('source_revision');
            $table->string('quality_state', 32)->default('unverified')->after('content_hash');
            $table->index(['timeframe', 'available_at'], 'market_candles_tf_available_idx');
            $table->index(['quality_state', 'is_final'], 'market_candles_quality_final_idx');
        });

        Schema::create('strategy_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('version', 64);
            $table->string('schema_version', 32)->default('1.0');
            $table->string('engine_version', 64);
            $table->string('status', 24)->default('draft');
            $table->string('content_hash', 64)->unique();
            $table->jsonb('definition_json');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version'], 'strategy_versions_name_version_unique');
            $table->index(['status', 'activated_at'], 'strategy_versions_status_active_idx');
        });

        Schema::create('universe_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('version', 64);
            $table->string('status', 24)->default('draft');
            $table->string('content_hash', 64)->unique();
            $table->jsonb('symbols_json');
            $table->jsonb('rules_json')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['name', 'version'], 'universe_versions_name_version_unique');
        });

        Schema::create('research_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 32);
            $table->string('schema_version', 32)->default('1.0');
            $table->string('content_hash', 64)->unique();
            $table->timestamp('source_window_start')->nullable();
            $table->timestamp('source_window_end')->nullable();
            $table->unsignedBigInteger('row_count')->default(0);
            $table->jsonb('inputs_json');
            $table->jsonb('quality_json')->nullable();
            $table->timestamp('frozen_at');
            $table->timestamps();

            $table->index(['kind', 'frozen_at'], 'research_manifests_kind_frozen_idx');
        });

        Schema::create('engine_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version', 32)->default('1.0');
            $table->string('kind', 32);
            $table->foreignId('strategy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('universe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('research_manifest_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('as_of');
            $table->timestamp('valid_until')->nullable();
            $table->jsonb('payload_json');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('lease_owner')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['status', 'kind', 'created_at'], 'engine_jobs_claim_idx');
            $table->index(['status', 'lease_expires_at'], 'engine_jobs_lease_idx');
            $table->index(['as_of', 'kind'], 'engine_jobs_asof_kind_idx');
        });

        Schema::create('engine_results', function (Blueprint $table): void {
            $table->id();
            $table->uuid('engine_job_id')->unique();
            $table->foreign('engine_job_id')->references('id')->on('engine_jobs')->cascadeOnDelete();
            $table->string('result_kind', 32);
            $table->string('engine_version', 64);
            $table->string('schema_version', 32)->default('1.0');
            $table->timestamp('as_of');
            $table->timestamp('valid_until')->nullable();
            $table->string('manifest_hash', 64);
            $table->jsonb('payload_json');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['result_kind', 'created_at'], 'engine_results_kind_created_idx');
            $table->index(['consumed_at', 'created_at'], 'engine_results_consumed_idx');
        });

        Schema::create('venue_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('venue', 24);
            $table->string('product_id', 64);
            $table->string('base_asset', 24);
            $table->string('quote_asset', 24);
            $table->string('status', 24)->default('online');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['venue', 'product_id', 'valid_from'], 'venue_products_version_unique');
            $table->index(['base_asset', 'quote_asset', 'valid_to'], 'venue_products_pair_active_idx');
        });

        Schema::create('ingestion_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('stream', 64);
            $table->string('partition_key', 128)->default('default');
            $table->string('cursor')->nullable();
            $table->unsignedBigInteger('last_sequence')->nullable();
            $table->timestamp('event_time')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('status', 24)->default('healthy');
            $table->jsonb('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['source', 'stream', 'partition_key'], 'ingestion_checkpoints_stream_unique');
            $table->index(['status', 'updated_at'], 'ingestion_checkpoints_health_idx');
        });

        Schema::create('fee_schedule_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('venue', 24);
            $table->string('account_scope', 64)->default('retail_conservative');
            $table->decimal('maker_fee_bps', 12, 6);
            $table->decimal('taker_fee_bps', 12, 6);
            $table->decimal('volume_tier_usd', 24, 8)->nullable();
            $table->timestamp('effective_at');
            $table->timestamp('expires_at')->nullable();
            $table->string('source_url')->nullable();
            $table->string('content_hash', 64);
            $table->jsonb('raw_json')->nullable();
            $table->timestamps();

            $table->unique(['venue', 'account_scope', 'effective_at'], 'fee_schedule_snapshots_version_unique');
            $table->index(['venue', 'expires_at'], 'fee_schedule_snapshots_active_idx');
        });

        Schema::create('data_quality_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32);
            $table->string('stream', 64);
            $table->string('severity', 16);
            $table->string('incident_type', 48);
            $table->timestamp('started_at');
            $table->timestamp('resolved_at')->nullable();
            $table->text('message');
            $table->jsonb('context_json')->nullable();
            $table->timestamps();

            $table->index(['source', 'stream', 'resolved_at'], 'data_quality_incidents_open_idx');
            $table->index(['severity', 'started_at'], 'data_quality_incidents_severity_idx');
        });

        Schema::create('market_candle_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_candle_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->string('content_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('available_at');
            $table->jsonb('values_json');
            $table->string('change_reason', 64)->default('source_revision');
            $table->timestamps();

            $table->unique(['market_candle_id', 'revision'], 'market_candle_revisions_unique');
            $table->index(['available_at', 'market_candle_id'], 'market_candle_revisions_available_idx');
        });

        $this->createRawMarketEvents();

        Schema::create('order_book_summaries', function (Blueprint $table): void {
            $table->id();
            $table->string('venue', 24);
            $table->string('product_id', 64);
            $table->timestamp('bucket_time');
            $table->timestamp('event_time');
            $table->timestamp('received_at');
            $table->unsignedBigInteger('sequence')->nullable();
            $table->decimal('best_bid', 20, 8)->nullable();
            $table->decimal('best_ask', 20, 8)->nullable();
            $table->decimal('spread_bps', 12, 6)->nullable();
            $table->unsignedInteger('book_age_ms');
            $table->boolean('is_valid')->default(false);
            $table->string('invalid_reason', 48)->nullable();
            $table->jsonb('depth_json');
            $table->timestamps();

            $table->unique(['venue', 'product_id', 'bucket_time'], 'order_book_summaries_bucket_unique');
            $table->index(['product_id', 'bucket_time'], 'order_book_summaries_product_time_idx');
            $table->index(['venue', 'is_valid', 'bucket_time'], 'order_book_summaries_valid_idx');
        });

        Schema::create('spread_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('product_id', 64);
            $table->string('buy_venue', 24);
            $table->string('sell_venue', 24);
            $table->decimal('notional_usd', 20, 8);
            $table->timestamp('observed_at');
            $table->unsignedInteger('buy_book_age_ms');
            $table->unsignedInteger('sell_book_age_ms');
            $table->unsignedInteger('receive_delta_ms');
            $table->decimal('buy_vwap', 20, 8)->nullable();
            $table->decimal('sell_vwap', 20, 8)->nullable();
            $table->decimal('gross_edge_bps', 14, 6)->nullable();
            $table->decimal('net_edge_bps', 14, 6)->nullable();
            $table->decimal('buy_fee_bps', 12, 6)->nullable();
            $table->decimal('sell_fee_bps', 12, 6)->nullable();
            $table->decimal('impact_bps', 12, 6)->default(0);
            $table->decimal('rebalance_reserve_bps', 12, 6)->default(0);
            $table->decimal('safety_buffer_bps', 12, 6)->default(10);
            $table->string('classification', 32);
            $table->unsignedInteger('opportunity_lifetime_ms')->nullable();
            $table->jsonb('delay_outcomes_json')->nullable();
            $table->jsonb('rejection_reasons_json')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'buy_venue', 'sell_venue', 'notional_usd', 'observed_at'], 'spread_observations_point_unique');
            $table->index(['classification', 'observed_at'], 'spread_observations_class_time_idx');
            $table->index(['product_id', 'observed_at'], 'spread_observations_product_time_idx');
        });

        Schema::create('sentiment_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 32)->default('alternative_me');
            $table->timestamp('published_at');
            $table->timestamp('first_seen_at');
            $table->unsignedTinyInteger('raw_value');
            $table->decimal('normalized_score', 8, 6);
            $table->decimal('change_1d', 10, 6)->nullable();
            $table->decimal('change_7d', 10, 6)->nullable();
            $table->decimal('zscore_30d', 10, 6)->nullable();
            $table->decimal('zscore_90d', 10, 6)->nullable();
            $table->string('classification', 32)->nullable();
            $table->jsonb('raw_json');
            $table->timestamps();

            $table->unique(['source', 'published_at'], 'sentiment_observations_source_time_unique');
            $table->index(['published_at', 'first_seen_at'], 'sentiment_observations_point_in_time_idx');
        });

        Schema::table('trade_decisions', function (Blueprint $table): void {
            $table->uuid('engine_job_id')->nullable()->after('strategy_run_id');
            $table->foreign('engine_job_id')->references('id')->on('engine_jobs')->nullOnDelete();
            $table->timestamp('signal_expires_at')->nullable()->after('approved_at');
            $table->unique(['engine_job_id', 'asset_id'], 'trade_decisions_engine_asset_unique');
        });

        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->foreignId('strategy_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('universe_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('research_manifest_id')->nullable()->constrained()->nullOnDelete();
            $table->jsonb('spec_json')->nullable();
            $table->jsonb('result_json')->nullable();
            $table->boolean('holdout_locked')->default(true);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION reject_immutable_research_mutation()
                RETURNS trigger AS $$
                BEGIN
                    IF TG_TABLE_NAME = 'research_manifests' OR OLD.status <> 'draft' THEN
                        RAISE EXCEPTION 'immutable research records cannot be modified';
                    END IF;
                    IF TG_OP = 'DELETE' THEN
                        RETURN OLD;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql
            SQL);
            DB::statement('CREATE TRIGGER strategy_versions_immutable BEFORE UPDATE OR DELETE ON strategy_versions FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
            DB::statement('CREATE TRIGGER universe_versions_immutable BEFORE UPDATE OR DELETE ON universe_versions FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
            DB::statement('CREATE TRIGGER research_manifests_immutable BEFORE UPDATE OR DELETE ON research_manifests FOR EACH ROW EXECUTE FUNCTION reject_immutable_research_mutation()');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS strategy_versions_immutable ON strategy_versions');
            DB::statement('DROP TRIGGER IF EXISTS universe_versions_immutable ON universe_versions');
            DB::statement('DROP TRIGGER IF EXISTS research_manifests_immutable ON research_manifests');
            DB::statement('DROP FUNCTION IF EXISTS reject_immutable_research_mutation()');
        }
        Schema::table('backtest_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('strategy_version_id');
            $table->dropConstrainedForeignId('universe_version_id');
            $table->dropConstrainedForeignId('research_manifest_id');
            $table->dropColumn(['spec_json', 'result_json', 'holdout_locked']);
        });

        Schema::table('trade_decisions', function (Blueprint $table): void {
            $table->dropUnique('trade_decisions_engine_asset_unique');
            $table->dropForeign(['engine_job_id']);
            $table->dropColumn(['engine_job_id', 'signal_expires_at']);
        });

        Schema::dropIfExists('sentiment_observations');
        Schema::dropIfExists('spread_observations');
        Schema::dropIfExists('order_book_summaries');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE IF EXISTS raw_market_events CASCADE');
        } else {
            Schema::dropIfExists('raw_market_events');
        }
        Schema::dropIfExists('market_candle_revisions');
        Schema::dropIfExists('data_quality_incidents');
        Schema::dropIfExists('fee_schedule_snapshots');
        Schema::dropIfExists('ingestion_checkpoints');
        Schema::dropIfExists('venue_products');
        Schema::dropIfExists('engine_results');
        Schema::dropIfExists('engine_jobs');
        Schema::dropIfExists('research_manifests');
        Schema::dropIfExists('universe_versions');
        Schema::dropIfExists('strategy_versions');

        Schema::table('market_candles', function (Blueprint $table): void {
            $table->dropIndex('market_candles_tf_available_idx');
            $table->dropIndex('market_candles_quality_final_idx');
            $table->dropColumn(['first_seen_at', 'available_at', 'is_final', 'source_revision', 'content_hash', 'quality_state']);
        });
    }

    private function createRawMarketEvents(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE TABLE raw_market_events (
                    id bigserial NOT NULL,
                    venue varchar(24) NOT NULL,
                    product_id varchar(64) NOT NULL,
                    channel varchar(32) NOT NULL,
                    event_type varchar(32) NOT NULL,
                    sequence bigint NULL,
                    event_time timestamptz NOT NULL,
                    received_at timestamptz NOT NULL,
                    payload_json jsonb NOT NULL,
                    created_at timestamptz NULL,
                    updated_at timestamptz NULL,
                    PRIMARY KEY (id, received_at)
                ) PARTITION BY RANGE (received_at)
            SQL);
            for ($offset = -7; $offset <= 7; $offset++) {
                $from = now('UTC')->addDays($offset)->startOfDay();
                $to = $from->copy()->addDay();
                DB::statement(sprintf(
                    "CREATE TABLE raw_market_events_%s PARTITION OF raw_market_events FOR VALUES FROM ('%s') TO ('%s')",
                    $from->format('Ymd'), $from->format('Y-m-d H:i:sP'), $to->format('Y-m-d H:i:sP'),
                ));
            }
            DB::statement('CREATE TABLE raw_market_events_default PARTITION OF raw_market_events DEFAULT');
            DB::statement('CREATE INDEX raw_market_events_product_time_idx ON raw_market_events (venue, product_id, received_at)');
            DB::statement('CREATE INDEX raw_market_events_retention_idx ON raw_market_events (received_at)');

            return;
        }

        Schema::create('raw_market_events', function (Blueprint $table): void {
            $table->id();
            $table->string('venue', 24);
            $table->string('product_id', 64);
            $table->string('channel', 32);
            $table->string('event_type', 32);
            $table->unsignedBigInteger('sequence')->nullable();
            $table->timestamp('event_time');
            $table->timestamp('received_at');
            $table->jsonb('payload_json');
            $table->timestamps();
            $table->index(['venue', 'product_id', 'received_at'], 'raw_market_events_product_time_idx');
            $table->index('received_at', 'raw_market_events_retention_idx');
        });
    }
};
