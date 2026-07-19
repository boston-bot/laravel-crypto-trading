<?php

namespace App\Services\Operations;

use App\Models\OperatorAction;
use Illuminate\Support\Str;

class OperatorActionService
{
    /** @param array<string, mixed> $request */
    public function queue(string $type, string $idempotencyKey, array $request = [], ?string $targetType = null, string|int|null $targetId = null): OperatorAction
    {
        return OperatorAction::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'id' => (string) Str::uuid(),
                'action_type' => $type,
                'actor' => 'local-operator',
                'status' => 'queued',
                'target_type' => $targetType,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'request_json' => $request,
            ],
        );
    }
}
