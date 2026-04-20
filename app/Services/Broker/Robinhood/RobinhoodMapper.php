<?php

namespace App\Services\Broker\Robinhood;

use App\Enums\BrokerType;
use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class RobinhoodMapper
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function mapAccount(array $payload): array
    {
        $buyingPower = $this->toFloat($payload['buying_power'] ?? $payload['crypto_buying_power'] ?? 0);
        $cashBalance = $this->toFloat($payload['cash_available_for_withdrawal'] ?? $payload['cash_balance'] ?? null);
        $equity = $this->toFloat($payload['portfolio_value'] ?? $payload['equity'] ?? $payload['total_equity'] ?? null);

        if (($cashBalance ?? 0.0) <= 0.0 && ($buyingPower ?? 0.0) > 0.0) {
            $cashBalance = $buyingPower;
        }

        if (($equity ?? 0.0) <= 0.0) {
            // Some Robinhood account payloads only return buying_power.
            $equity = max((float) ($buyingPower ?? 0.0), (float) ($cashBalance ?? 0.0));
        }

        return [
            'external_account_id' => (string) ($payload['id'] ?? $payload['account_id'] ?? $payload['account_number'] ?? Str::uuid()),
            'account_type' => $payload['account_type'] ?? null,
            'currency' => strtoupper((string) ($payload['currency_code'] ?? $payload['currency'] ?? 'USD')),
            'buying_power' => $buyingPower,
            'cash_balance' => $cashBalance,
            'equity' => $equity,
            'status' => (string) ($payload['status'] ?? 'active'),
            'snapshot_at' => now(),
            'raw_json' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function mapAsset(array $payload): array
    {
        $symbol = (string) ($payload['symbol'] ?? $payload['asset_code'] ?? $payload['base_currency_code'] ?? '');
        $symbol = strtoupper(str_replace(['-USD', '/USD', '-USDC'], '', $symbol));

        return [
            'symbol' => $symbol,
            'asset_type' => (string) ($payload['type'] ?? 'crypto'),
            'is_tradable' => (bool) ($payload['tradable'] ?? $payload['is_tradable'] ?? true),
            'min_order_notional' => $this->toFloat($payload['min_order_size'] ?? $payload['min_order_notional'] ?? 0),
            'price_precision' => $this->toInt($payload['price_increment'] ?? $payload['price_precision'] ?? null),
            'quantity_precision' => $this->toInt($payload['quantity_increment'] ?? $payload['quantity_precision'] ?? null),
            'metadata_json' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $assetIdBySymbol
     * @return array<string, mixed>
     */
    public function mapPosition(array $payload, array $assetIdBySymbol): array
    {
        $symbol = strtoupper((string) ($payload['asset_code'] ?? $payload['symbol'] ?? $payload['currency_code'] ?? ''));

        return [
            'asset_id' => $assetIdBySymbol[$symbol] ?? null,
            'quantity' => $this->toFloat($payload['quantity'] ?? $payload['total_quantity'] ?? 0),
            'avg_cost' => $this->toFloat($payload['average_buy_price'] ?? $payload['average_price'] ?? 0),
            'market_value' => $this->toFloat($payload['market_value'] ?? $payload['quantity'] ?? 0),
            'unrealized_pnl' => $this->toFloat($payload['unrealized_profit_loss'] ?? $payload['unrealized_pnl'] ?? 0),
            'snapshot_at' => $this->toDateTime($payload['updated_at'] ?? null) ?? now(),
            'raw_json' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, int> $assetIdBySymbol
     * @return array<string, mixed>
     */
    public function mapOrder(array $payload, array $assetIdBySymbol): array
    {
        $symbol = strtoupper((string) ($payload['asset_code'] ?? $payload['symbol'] ?? ''));
        $side = Str::lower((string) ($payload['side'] ?? 'buy'));
        $status = Str::lower((string) ($payload['state'] ?? $payload['status'] ?? OrderStatus::SUBMITTED->value));

        return [
            'asset_id' => $assetIdBySymbol[$symbol] ?? null,
            'external_order_id' => (string) ($payload['id'] ?? Str::uuid()),
            'client_order_id' => (string) ($payload['client_order_id'] ?? Str::uuid()),
            'side' => in_array($side, [OrderSide::BUY->value, OrderSide::SELL->value], true) ? $side : OrderSide::BUY->value,
            'order_type' => (string) ($payload['type'] ?? 'market'),
            'time_in_force' => $payload['time_in_force'] ?? null,
            'requested_quantity' => $this->toFloat($payload['quantity'] ?? 0),
            'requested_notional' => $this->toFloat($payload['notional'] ?? $payload['quote_amount'] ?? 0),
            'requested_price' => $this->toFloat($payload['price'] ?? 0),
            'status' => $this->normalizeOrderStatus($status),
            'filled_quantity' => $this->toFloat($payload['executed_quantity'] ?? $payload['filled_quantity'] ?? 0),
            'filled_notional' => $this->toFloat($payload['executed_notional'] ?? $payload['filled_notional'] ?? 0),
            'avg_fill_price' => $this->toFloat($payload['average_price'] ?? $payload['average_fill_price'] ?? 0),
            'submitted_at' => $this->toDateTime($payload['created_at'] ?? null) ?? now(),
            'filled_at' => $this->toDateTime($payload['updated_at'] ?? null),
            'raw_response_json' => $payload,
        ];
    }

    public function buildAssetLookup(): array
    {
        return Asset::query()
            ->where('broker', BrokerType::ROBINHOOD->value)
            ->pluck('id', 'symbol')
            ->mapWithKeys(static fn (int $id, string $symbol) => [strtoupper($symbol) => $id])
            ->all();
    }

    private function normalizeOrderStatus(string $status): string
    {
        return match ($status) {
            'queued', 'confirmed' => OrderStatus::SUBMITTED->value,
            'partially_filled' => OrderStatus::PARTIALLY_FILLED->value,
            'filled' => OrderStatus::FILLED->value,
            'canceled', 'cancelled' => OrderStatus::CANCELLED->value,
            'rejected', 'failed' => OrderStatus::REJECTED->value,
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

    private function toInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (string) (int) $value === (string) $value) {
            return (int) $value;
        }

        $normalized = (string) $value;
        if (! str_contains($normalized, '.')) {
            return null;
        }

        $fraction = Arr::last(explode('.', $normalized));

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
