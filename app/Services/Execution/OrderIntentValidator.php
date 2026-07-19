<?php

namespace App\Services\Execution;

use App\Models\Asset;
use InvalidArgumentException;

class OrderIntentValidator
{
    /** @return array<string, mixed> */
    public function validate(array $intent, Asset $asset): array
    {
        foreach (['venue', 'product_id', 'side', 'normalized_base_quantity', 'reference_price', 'earliest_execution_at', 'idempotency_key', 'intent_hash'] as $field) {
            if (! array_key_exists($field, $intent)) { throw new InvalidArgumentException("Order intent is missing {$field}."); }
        }
        if ($intent['venue'] !== 'coinbase' || strtoupper((string) $intent['product_id']) !== strtoupper($asset->symbol.'-USD')) {
            throw new InvalidArgumentException('Order intent venue or product does not match the asset.');
        }
        $quantity = (float) $intent['normalized_base_quantity']; $price = (float) $intent['reference_price'];
        if (! is_finite($quantity) || ! is_finite($price) || $quantity <= 0 || $price <= 0) { throw new InvalidArgumentException('Order intent quantity and price must be finite and positive.'); }
        $precision = $asset->quantity_precision ?? 8;
        if (round($quantity, $precision) !== $quantity) { throw new InvalidArgumentException('Order intent quantity violates the pinned base increment.'); }
        $minimum = (float) ($intent['minimum_notional'] ?? $asset->min_order_notional ?? 0);
        if ($quantity * $price + 1e-10 < $minimum) { throw new InvalidArgumentException('Order intent is below the pinned minimum notional.'); }
        $declaredHash = (string) $intent['intent_hash']; unset($intent['intent_hash']);
        $this->sortRecursively($intent);
        $actualHash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if (! hash_equals($declaredHash, $actualHash)) { throw new InvalidArgumentException('Order intent hash mismatch.'); }

        return $intent + ['intent_hash' => $declaredHash];
    }

    private function sortRecursively(array &$value): void
    {
        if (! array_is_list($value)) { ksort($value); }
        foreach ($value as &$child) { if (is_array($child)) { $this->sortRecursively($child); } }
    }
}
