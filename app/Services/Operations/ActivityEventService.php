<?php

namespace App\Services\Operations;

use App\Models\ActivityEvent;

class ActivityEventService
{
    /** @param array<string, mixed> $detail */
    public function record(
        string $sourceType,
        string|int $sourceId,
        string $eventType,
        string $category,
        string $title,
        string $explanation,
        string $severity = 'info',
        ?int $accountId = null,
        ?int $assetId = null,
        ?string $cycleId = null,
        array $detail = [],
    ): ActivityEvent {
        return ActivityEvent::query()->firstOrCreate(
            ['source_type' => $sourceType, 'source_id' => (string) $sourceId, 'event_type' => $eventType],
            [
                'category' => $category,
                'severity' => $severity,
                'title' => $title,
                'explanation' => $explanation,
                'occurred_at' => now(),
                'broker_account_id' => $accountId,
                'asset_id' => $assetId,
                'pipeline_cycle_id' => $cycleId,
                'detail_json' => $detail,
            ],
        );
    }
}
