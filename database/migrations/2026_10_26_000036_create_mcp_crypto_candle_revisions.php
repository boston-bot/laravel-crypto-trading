<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A later differing observation of a stored candle. The first stored candle is never changed; daily revisions
        // inside a product's (or the benchmark's) history window make that product unavailable until reviewed.
        Schema::create('mcp_crypto_candle_revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mcp_crypto_market_read_id')->constrained();
            $t->foreignId('candle_id')->constrained('mcp_crypto_candles');
            $t->string('venue', 16);
            $t->string('product_id', 24);
            $t->string('granularity', 16);
            $t->timestampTz('starts_at', 6);
            $t->decimal('open', 38, 18);
            $t->decimal('high', 38, 18);
            $t->decimal('low', 38, 18);
            $t->decimal('close', 38, 18);
            $t->decimal('volume', 38, 18);
            $t->string('fingerprint', 64);
            $t->timestampTz('retrieved_at', 6);
            $t->unique(['candle_id', 'fingerprint'], 'crypto_candle_revision_identity');
            $t->index(['venue', 'granularity', 'product_id', 'starts_at'], 'crypto_candle_revision_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_crypto_candle_revisions');
    }
};
