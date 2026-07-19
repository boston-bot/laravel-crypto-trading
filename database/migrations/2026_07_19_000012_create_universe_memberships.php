<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('universe_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('universe_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('venue_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('venue', 24);
            $table->string('product_id', 64);
            $table->string('quote_asset', 24)->default('USD');
            $table->timestamp('valid_from');
            $table->timestamp('valid_to')->nullable();
            $table->timestamp('listed_at')->nullable();
            $table->timestamp('delisted_at')->nullable();
            $table->string('trading_state', 24);
            $table->unsignedSmallInteger('price_precision')->nullable();
            $table->unsignedSmallInteger('quantity_precision')->nullable();
            $table->decimal('minimum_notional', 24, 8)->nullable();
            $table->jsonb('evidence_json');
            $table->string('evidence_hash', 64);
            $table->timestamps();

            $table->unique(['universe_version_id', 'asset_id'], 'universe_memberships_version_asset_unique');
            $table->index(['asset_id', 'valid_from', 'valid_to'], 'universe_memberships_asset_validity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('universe_memberships');
    }
};
