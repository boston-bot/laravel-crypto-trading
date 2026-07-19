<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_order_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('paper_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trade_decision_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('broker_order_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('intent_hash', 64);
            $table->string('side', 8);
            $table->decimal('amount', 20, 8)->default(0);
            $table->decimal('reserved_quantity', 24, 12)->default(0);
            $table->string('status', 16)->default('reserved');
            $table->timestamp('reserved_at');
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();

            $table->unique(['paper_session_id', 'idempotency_key'], 'paper_reservations_session_intent_unique');
            $table->index(['paper_session_id', 'status'], 'paper_reservations_session_status_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE paper_order_reservations ADD CONSTRAINT paper_reservations_amount_nonnegative CHECK (amount >= 0 AND reserved_quantity >= 0)');
            DB::statement("ALTER TABLE paper_order_reservations ADD CONSTRAINT paper_reservations_status_valid CHECK (status IN ('reserved', 'filled', 'released'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paper_order_reservations');
    }
};
