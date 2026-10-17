<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_incidents', function (Blueprint $t) {
            $t->string('source', 16)->default('monitoring');
            $t->timestampTz('resolved_at')->nullable();
            $t->unsignedInteger('reopen_count')->default(0);
            $t->timestampTz('last_notified_at')->nullable();
            $t->unsignedInteger('notified_count')->default(0);
        });
        // One row per delivery attempt. Only a provider acknowledgement is recorded as delivered.
        Schema::create('mcp_notification_deliveries', function (Blueprint $t) {
            $t->id();
            $t->uuid('outbox_id')->nullable()->index();
            $t->foreignId('incident_id')->nullable()->constrained('mcp_incidents');
            $t->string('kind', 16);
            $t->string('channel', 16);
            $t->string('status', 16);
            $t->string('provider_message_id', 128)->nullable();
            $t->string('error_code', 64)->nullable();
            $t->timestampTz('attempted_at');
        });
        Schema::create('mcp_component_status', function (Blueprint $t) {
            $t->id();
            $t->string('component', 64)->unique();
            $t->string('status', 32);
            $t->text('detail')->nullable();
            $t->timestampTz('observed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_component_status');
        Schema::dropIfExists('mcp_notification_deliveries');
        Schema::table('mcp_incidents', function (Blueprint $t) {
            $t->dropColumn(['source', 'resolved_at', 'reopen_count', 'last_notified_at', 'notified_count']);
        });
    }
};
