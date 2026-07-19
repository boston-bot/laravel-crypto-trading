<?php

namespace App\Services\PaperTrading;

use App\Enums\OrderSide;
use App\Models\Asset;
use App\Models\BrokerOrder;
use App\Models\PaperLedgerEntry;
use App\Models\PaperOrderReservation;
use App\Models\PaperPosition;
use App\Models\PaperSession;
use App\Models\TradeDecision;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaperReservationService
{
    public function reserve(
        PaperSession $session,
        TradeDecision $decision,
        Asset $asset,
        OrderSide $side,
        float $amount,
        float $quantity,
        string $intentHash,
    ): PaperOrderReservation {
        if ($amount < 0 || $quantity < 0 || ! preg_match('/^[a-f0-9]{64}$/', $intentHash)) {
            throw new RuntimeException('Paper reservation values or intent hash are invalid.');
        }

        return DB::transaction(function () use ($session, $decision, $asset, $side, $amount, $quantity, $intentHash): PaperOrderReservation {
            $lockedSession = PaperSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($lockedSession->status !== 'active') {
                throw new RuntimeException('Paper reservations require an active session.');
            }
            $existing = PaperOrderReservation::query()
                ->where('paper_session_id', $lockedSession->id)
                ->where('idempotency_key', $decision->idempotency_key)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_hash, $intentHash)) {
                    throw new RuntimeException('The paper intent idempotency key was reused with different content.');
                }

                return $existing;
            }

            if ($side === OrderSide::BUY) {
                $cash = (float) PaperLedgerEntry::query()->where('paper_session_id', $lockedSession->id)->sum('cash_delta');
                $available = round($cash - (float) $lockedSession->reserved_cash, 8);
                if ($available + 0.00000001 < $amount) {
                    throw new RuntimeException(sprintf('Paper order requires $%.2f, but only $%.2f is available.', $amount, $available));
                }
            } else {
                $position = PaperPosition::query()
                    ->where('paper_session_id', $lockedSession->id)
                    ->where('asset_id', $asset->id)
                    ->lockForUpdate()
                    ->first();
                $alreadyReserved = (float) PaperOrderReservation::query()
                    ->where('paper_session_id', $lockedSession->id)
                    ->where('asset_id', $asset->id)
                    ->where('side', OrderSide::SELL->value)
                    ->where('status', 'reserved')
                    ->sum('reserved_quantity');
                $availableQuantity = max(0.0, (float) ($position?->quantity ?? 0) - $alreadyReserved);
                if ($availableQuantity + 0.000000000001 < $quantity) {
                    throw new RuntimeException('Paper sell quantity exceeds the unreserved active-session position.');
                }
            }

            $reservation = PaperOrderReservation::query()->create([
                'paper_session_id' => $lockedSession->id,
                'asset_id' => $asset->id,
                'trade_decision_id' => $decision->id,
                'idempotency_key' => (string) $decision->idempotency_key,
                'intent_hash' => $intentHash,
                'side' => $side->value,
                'amount' => $side === OrderSide::BUY ? round($amount, 8) : 0,
                'reserved_quantity' => $side === OrderSide::SELL ? round($quantity, 12) : 0,
                'status' => 'reserved',
                'reserved_at' => now(),
            ]);

            if ($side === OrderSide::BUY) {
                $lockedSession->update(['reserved_cash' => round((float) $lockedSession->reserved_cash + $amount, 8)]);
            }
            PaperLedgerEntry::query()->create([
                'paper_session_id' => $lockedSession->id,
                'asset_id' => $asset->id,
                'entry_type' => 'cash_reservation',
                'reserved_cash_delta' => $side === OrderSide::BUY ? round($amount, 8) : 0,
                'quantity_delta' => 0,
                'occurred_at' => now(),
                'context_json' => [
                    'paper_order_reservation_id' => $reservation->id,
                    'idempotency_key' => $decision->idempotency_key,
                    'intent_hash' => $intentHash,
                    'side' => $side->value,
                    'reserved_quantity' => $side === OrderSide::SELL ? round($quantity, 12) : 0,
                ],
            ]);

            return $reservation;
        }, 3);
    }

    public function consume(int $reservationId, BrokerOrder $order, string $fillId): PaperOrderReservation
    {
        return DB::transaction(function () use ($reservationId, $order, $fillId): PaperOrderReservation {
            $reservation = PaperOrderReservation::query()->findOrFail($reservationId);
            $session = PaperSession::query()->whereKey($reservation->paper_session_id)->lockForUpdate()->firstOrFail();
            $reservation = PaperOrderReservation::query()->whereKey($reservationId)->lockForUpdate()->firstOrFail();
            if ($reservation->status === 'filled') {
                return $reservation;
            }
            if ($reservation->status !== 'reserved') {
                throw new RuntimeException('Only an active paper reservation can be converted to a fill.');
            }

            $amount = (float) $reservation->amount;
            if ($amount > 0) {
                $session->update(['reserved_cash' => round(max(0.0, (float) $session->reserved_cash - $amount), 8)]);
            }
            PaperLedgerEntry::query()->create([
                'paper_session_id' => $session->id,
                'asset_id' => $reservation->asset_id,
                'broker_order_id' => $order->id,
                'entry_type' => 'cash_reservation_release',
                'fill_id' => $fillId,
                'reserved_cash_delta' => -$amount,
                'occurred_at' => now(),
                'context_json' => ['paper_order_reservation_id' => $reservation->id, 'reason' => 'filled'],
            ]);
            $reservation->update([
                'broker_order_id' => $order->id,
                'status' => 'filled',
                'filled_at' => now(),
            ]);

            return $reservation->fresh();
        }, 3);
    }

    public function release(PaperOrderReservation $reservation, string $reason): PaperOrderReservation
    {
        return DB::transaction(function () use ($reservation, $reason): PaperOrderReservation {
            $session = PaperSession::query()->whereKey($reservation->paper_session_id)->lockForUpdate()->firstOrFail();
            $locked = PaperOrderReservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'reserved') {
                return $locked;
            }
            $amount = (float) $locked->amount;
            if ($amount > 0) {
                $session->update(['reserved_cash' => round(max(0.0, (float) $session->reserved_cash - $amount), 8)]);
            }
            PaperLedgerEntry::query()->create([
                'paper_session_id' => $session->id,
                'asset_id' => $locked->asset_id,
                'entry_type' => 'cash_reservation_release',
                'reserved_cash_delta' => -$amount,
                'occurred_at' => now(),
                'context_json' => ['paper_order_reservation_id' => $locked->id, 'reason' => $reason],
            ]);
            $locked->update(['status' => 'released', 'released_at' => now(), 'release_reason' => $reason]);

            return $locked->fresh();
        }, 3);
    }
}
