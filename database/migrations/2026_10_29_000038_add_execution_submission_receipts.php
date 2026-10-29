<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_execution_broker_orders', function (Blueprint $table) {
            // Encrypted, immutable evidence of the correlated placement response. No historical backfill.
            $table->text('submission_receipt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_execution_broker_orders', fn (Blueprint $table) => $table->dropColumn('submission_receipt'));
    }
};
