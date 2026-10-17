<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Thesis-specific events (breach, review due, targeted request) research that thesis, not whichever shares the symbol. */
    public function up(): void
    {
        Schema::table('mcp_research_events', function (Blueprint $t) {
            $t->unsignedBigInteger('thesis_version_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mcp_research_events', function (Blueprint $t) {
            $t->dropColumn('thesis_version_id');
        });
    }
};
