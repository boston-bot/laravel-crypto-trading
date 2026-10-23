<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Crypto C1: read-only public market data with provenance. Additive; no backfill. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_crypto_market_reads', function (Blueprint $t) {
            $t->id();
            $t->uuid('request_id')->unique();
            $t->string('venue', 16);
            $t->string('contract', 64);
            $t->string('trigger', 16);
            $t->string('status', 16);
            $t->string('error', 64)->nullable();
            $t->text('requested_products');
            $t->unsignedSmallInteger('products_complete')->default(0);
            $t->unsignedInteger('requests')->default(0);
            $t->unsignedInteger('candles_inserted')->default(0);
            $t->unsignedInteger('candle_conflicts')->default(0);
            $t->timestampTz('started_at', 6);
            $t->timestampTz('finished_at', 6)->nullable();
            $t->index(['venue', 'status', 'finished_at'], 'crypto_market_read_history');
        });

        Schema::create('mcp_crypto_product_snapshots', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_crypto_market_read_id')->constrained();
            $t->string('venue', 16);
            $t->string('product_id', 24);
            $t->string('base_currency', 12);
            $t->string('quote_currency', 12);
            $t->string('product_type', 16);
            $t->string('status', 24);
            $t->boolean('trading_disabled');
            $t->boolean('is_disabled');
            $t->boolean('view_only');
            $t->boolean('cancel_only');
            $t->boolean('limit_only');
            $t->boolean('post_only');
            $t->boolean('auction_mode');
            $t->decimal('base_increment', 38, 18);
            $t->decimal('quote_increment', 38, 18);
            $t->decimal('price_increment', 38, 18);
            $t->decimal('base_min_size', 38, 18);
            $t->decimal('base_max_size', 38, 18);
            $t->decimal('quote_min_size', 38, 18);
            $t->decimal('quote_max_size', 38, 18);
            $t->decimal('price', 38, 18)->nullable();
            $t->decimal('volume_24h', 38, 18)->nullable();
            $t->decimal('quote_volume_24h', 38, 18)->nullable();
            $t->timestampTz('retrieved_at', 6);
            $t->unique(['mcp_crypto_market_read_id', 'product_id'], 'crypto_product_snapshot_read');
            $t->index(['venue', 'product_id', 'retrieved_at'], 'crypto_product_snapshot_history');
        });

        Schema::create('mcp_crypto_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_crypto_market_read_id')->constrained();
            $t->string('venue', 16);
            $t->string('product_id', 24);
            $t->decimal('bid', 38, 18);
            $t->decimal('bid_size', 38, 18);
            $t->decimal('ask', 38, 18);
            $t->decimal('ask_size', 38, 18);
            $t->timestampTz('source_at', 6);
            $t->timestampTz('retrieved_at', 6);
            $t->unique(['mcp_crypto_market_read_id', 'product_id'], 'crypto_quote_read');
            $t->index(['venue', 'product_id', 'source_at'], 'crypto_quote_history');
        });

        Schema::create('mcp_crypto_candles', function (Blueprint $t) {
            $t->id();
            $t->string('venue', 16);
            $t->string('product_id', 24);
            $t->string('granularity', 16);
            $t->timestampTz('starts_at', 6);
            $t->timestampTz('ends_at', 6);
            $t->decimal('open', 38, 18);
            $t->decimal('high', 38, 18);
            $t->decimal('low', 38, 18);
            $t->decimal('close', 38, 18);
            $t->decimal('volume', 38, 18);
            // Provenance of the first observation; later differing observations are counted on their read, not applied.
            $t->foreignId('mcp_crypto_market_read_id')->constrained();
            $t->timestampTz('retrieved_at', 6);
            $t->unique(['venue', 'product_id', 'granularity', 'starts_at'], 'crypto_candle_identity');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $schema = '"mcp_trading"';
            DB::statement("ALTER TABLE {$schema}.\"mcp_crypto_market_reads\" ADD CONSTRAINT crypto_market_read_states CHECK (venue = 'coinbase' AND trigger IN ('scheduled','manual') AND status IN ('running','complete','partial','failed'))");
            DB::statement("ALTER TABLE {$schema}.\"mcp_crypto_quotes\" ADD CONSTRAINT crypto_quote_values CHECK (bid > 0 AND ask >= bid AND bid_size >= 0 AND ask_size >= 0)");
            DB::statement("ALTER TABLE {$schema}.\"mcp_crypto_candles\" ADD CONSTRAINT crypto_candle_values CHECK (granularity IN ('FIVE_MINUTE','ONE_DAY') AND ends_at > starts_at AND low > 0 AND low <= open AND low <= close AND high >= open AND high >= close AND volume >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_crypto_candles');
        Schema::dropIfExists('mcp_crypto_quotes');
        Schema::dropIfExists('mcp_crypto_product_snapshots');
        Schema::dropIfExists('mcp_crypto_market_reads');
    }
};
