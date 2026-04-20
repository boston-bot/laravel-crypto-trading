<?php

namespace App\Services\Broker\Coinbase;

use App\Enums\BrokerType;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CoinbaseMapper
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mapAccount(array $payload): array
    {
        $available = $this->toFloat(data_get($payload, 'available_balance.value'));
        $hold = $this->toFloat(data_get($payload, 'hold.value'));
        $cash = (float) ($available ?? 0.0) + (float) ($hold ?? 0.0);

        return [
            'external_account_id' => (string) ($payload['uuid'] ?? 'coinbase-primary'),
            'account_type' => (string) ($payload['type'] ?? 'spot'),
            'currency' => strtoupper((string) ($payload['currency'] ?? 'USD')),
            'buying_power' => $cash,
            'cash_balance' => $cash,
            'equity' => $cash,
            'status' => $this->normalizeAccountStatus((string) ($payload['active'] ?? 'true')),
            'snapshot_at' => now(),
            'raw_json' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function mapAsset(array $payload): array
    {
        $symbol = (string) ($payload['base_currency_id'] ?? '');
        if ($symbol === '' && isset($payload['product_id'])) {
            $symbol = $this->symbolFromProduct((string) $payload['product_id']);
        }

        return [
            'symbol' => strtoupper($symbol),
            'asset_type' => (string) ($payload['product_type'] ?? 'crypto'),
            'is_tradable' => ! (bool) ($payload['trading_disabled'] ?? false)
                && ! (bool) ($payload['is_disabled'] ?? false),
            'min_order_notional' => $this->toFloat($payload['quote_min_size'] ?? $payload['base_min_size'] ?? null),
            'price_precision' => $this->toPrecision($payload['quote_increment'] ?? null),
            'quantity_precision' => $this->toPrecision($payload['base_increment'] ?? null),
            'metadata_json' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $assetIdBySymbol
     * @param  array<string, float>  $priceBySymbol
     * @return array<string, mixed>
     */
    public function mapPosition(array $payload, array $assetIdBySymbol, array $priceBySymbol = []): array
    {
        $symbol = strtoupper((string) ($payload['currency'] ?? ''));
        $quantity = (float) ($this->toFloat(data_get($payload, 'available_balance.value')) ?? 0.0)
            + (float) ($this->toFloat(data_get($payload, 'hold.value')) ?? 0.0);
        $marketPrice = $priceBySymbol[$symbol] ?? 0.0;

        return [
            'asset_id' => $assetIdBySymbol[$symbol] ?? null,
            'quantity' => $quantity,
            'avg_cost' => null,
            'market_value' => $marketPrice > 0 ? $quantity * $marketPrice : $quantity,
            'unrealized_pnl' => null,
            'snapshot_at' => now(),
            'raw_json' => $payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, int>  $assetIdBySymbol
     * @return array<string, mixed>
     */
    public function mapOrder(array $payload, array $assetIdBySymbol): array
    {
        $productId = strtoupper((string) ($payload['product_id'] ?? ''));
        $symbol = $this->symbolFromProduct($productId);
        $side = Str::lower((string) ($payload['side'] ?? 'buy'));
        $orderConfig = is_array($payload['order_configuration'] ?? null) ? $payload['order_configuration'] : [];
        $activeConfig = $this->resolveOrderConfig($orderConfig);
        $status = strtoupper((string) ($payload['status'] ?? 'OPEN'));

        return [
            'asset_id' => $assetIdBySymbol[$symbol] ?? null,
            'external_order_id' => (string) ($payload['order_id'] ?? Str::uuid()),
            'client_order_id' => (string) ($payload['client_order_id'] ?? Str::uuid()),
            'side' => in_array($side, [OrderSide::BUY->value, OrderSide::SELL->value], true) ? $side : OrderSide::BUY->value,
            'order_type' => $this->resolveOrderType(array_key_first($activeConfig)),
            'time_in_force' => (string) ($payload['time_in_force'] ?? null),
            'requested_quantity' => $this->toFloat($activeConfig['base_size'] ?? $payload['base_size'] ?? null),
            'requested_notional' => $this->toFloat($activeConfig['quote_size'] ?? $payload['quote_size'] ?? null),
            'requested_price' => $this->toFloat($activeConfig['limit_price'] ?? $payload['limit_price'] ?? null),
            'status' => $this->normalizeOrderStatus($status),
            'filled_quantity' => $this->toFloat($payload['filled_size'] ?? null),
            'filled_notional' => $this->toFloat($payload['filled_value'] ?? null),
            'avg_fill_price' => $this->toFloat($payload['average_filled_price'] ?? null),
            'submitted_at' => $this->toDateTime($payload['created_time'] ?? null) ?? now(),
            'filled_at' => $this->toDateTime($payload['last_fill_time'] ?? null),
            'raw_response_json' => $payload,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function buildAssetLookup(): array
    {
        return Asset::query()
            ->where('broker', BrokerType::COINBASE->value)
            ->pluck('id', 'symbol')
            ->mapWithKeys(static fn (int $id, string $symbol) => [strtoupper($symbol) => $id])
            ->all();
    }

    private function symbolFromProduct(string $productId): string
    {
        $normalized = strtoupper(trim($productId));
        if ($normalized === '') {
            return '';
        }

        return (string) Arr::first(explode('-', $normalized), fn (mixed $piece): bool => is_string($piece));
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function resolveOrderConfig(array $configuration): array
    {
        foreach ($configuration as $type => $value) {
            if (is_array($value)) {
                return [
                    $type => $value,
                    ...$value,
                ];
            }
        }

        return [];
    }

    private function resolveOrderType(?string $orderConfigKey): string
    {
        if (! is_string($orderConfigKey) || $orderConfigKey === '') {
            return 'market';
        }

        return match (true) {
            str_starts_with($orderConfigKey, 'limit_') => 'limit',
            str_starts_with($orderConfigKey, 'stop_limit_') => 'stop_limit',
            str_starts_with($orderConfigKey, 'trigger_bracket_') => 'trigger_bracket',
            default => 'market',
        };
    }

    private function normalizeAccountStatus(string $status): string
    {
        return in_array(Str::lower($status), ['true', 'active', 'enabled'], true) ? 'active' : 'inactive';
    }

    private function normalizeOrderStatus(string $status): string
    {
        return match ($status) {
            'OPEN', 'PENDING', 'QUEUED' => OrderStatus::SUBMITTED->value,
            'FILLED' => OrderStatus::FILLED->value,
            'CANCELLED', 'CANCELED' => OrderStatus::CANCELLED->value,
            'FAILED', 'REJECTED', 'EXPIRED' => OrderStatus::REJECTED->value,
            'PARTIALLY_FILLED' => OrderStatus::PARTIALLY_FILLED->value,
            default => OrderStatus::SUBMITTED->value,
        };
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private function toPrecision(mixed $value): ?int
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_contains($value, '.')) {
            return 0;
        }

        $fraction = Arr::last(explode('.', $value));

        return is_string($fraction) ? strlen(rtrim($fraction, '0')) : null;
    }

    private function toDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Carbon::parse($value);
    }
}
