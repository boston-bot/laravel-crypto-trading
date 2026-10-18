<?php

namespace App\Services\Research;

use App\Models\Research\Budget;
use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use LogicException;

/**
 * Application spending ledger. Fixed subscriptions, settled usage and outstanding reservations all count against
 * the envelope. Provider billing can lag, so this is a spending control, not an invoice guarantee.
 */
final class ResearchBudget
{
    public static function period(?CarbonImmutable $at = null): string
    {
        return ($at ?? CarbonImmutable::now())->utc()->format('Y-m');
    }

    /** @return array{envelope: Decimal, fixed: Decimal, input_rate: Decimal, output_rate: Decimal, reduce_at: Decimal} */
    public static function config(): array
    {
        $values = ['envelope' => config('research.monthly_envelope'), 'fixed' => config('research.fixed_monthly_costs'),
            'input_rate' => config('research.input_cost_per_million'), 'output_rate' => config('research.output_cost_per_million')];
        if (in_array(null, $values, true) || in_array('', $values, true)) {
            throw new ResearchFailure(ResearchError::CostUnreviewed);
        }
        try {
            $parsed = array_map(fn ($value) => Decimal::from($value), $values);
            $zero = Decimal::from('0');
            if ($parsed['envelope']->compareTo($zero) <= 0 || $parsed['envelope']->compareTo(Decimal::from(config('research.envelope_ceiling'))) > 0 ||
                $parsed['fixed']->compareTo($zero) < 0 || $parsed['input_rate']->compareTo($zero) < 0 || $parsed['output_rate']->compareTo($zero) < 0) {
                throw new ResearchFailure(ResearchError::BudgetConfigInvalid);
            }
        } catch (InvalidArgumentException) {
            throw new ResearchFailure(ResearchError::BudgetConfigInvalid);
        }

        return $parsed + ['reduce_at' => Decimal::from(config('research.reduce_at'))];
    }

    public function estimate(ModelRequest $request): Decimal
    {
        return $this->cost($request->inputBytes(), $request->maxOutputTokens);
    }

    public function cost(int $inputTokens, int $outputTokens): Decimal
    {
        $config = self::config();
        $million = Decimal::from('1000000');

        return Decimal::from((string) $inputTokens)->multipliedBy($config['input_rate'])->dividedBy($million, 18)
            ->plus(Decimal::from((string) $outputTokens)->multipliedBy($config['output_rate'])->dividedBy($million, 18));
    }

    /**
     * Call inside a transaction. The locked period row serializes reservations so concurrent calls cannot overspend.
     * Returns true when this reservation first crosses the reduction threshold.
     */
    public function reserve(string $period, Decimal $amount, bool $routine, ?Decimal $ceiling = null): bool
    {
        $config = self::config();
        // A paper mandate can tighten admission without relabeling the shared application's historical budget.
        $limit = $ceiling !== null && $ceiling->compareTo($config['envelope']) < 0 ? $ceiling : $config['envelope'];
        $row = $this->lockedRow($period, $config);
        $committed = $config['fixed']->plus(Decimal::from($row->actual))->plus(Decimal::from($row->reserved));
        $threshold = $limit->multipliedBy($config['reduce_at']);
        if ($committed->plus($amount)->compareTo($limit) > 0) {
            throw new ResearchFailure(ResearchError::BudgetExhausted);
        }
        if ($routine && $committed->compareTo($threshold) >= 0) {
            throw new ResearchFailure(ResearchError::BudgetReduced);
        }
        $globalThreshold = $config['envelope']->multipliedBy($config['reduce_at']);
        $crossed = $row->reduced_at === null && $committed->plus($amount)->compareTo($globalThreshold) >= 0;
        $row->update(['reserved' => (string) Decimal::from($row->reserved)->plus($amount), 'envelope' => (string) $config['envelope'],
            'fixed_costs' => (string) $config['fixed'], 'reduced_at' => $crossed ? now() : $row->reduced_at]);

        return $crossed;
    }

    /** Call inside a transaction. Releases a reservation and records the settled cost. */
    public function settle(string $period, Decimal $reserved, Decimal $actual): void
    {
        $row = Budget::where('period', $period)->lockForUpdate()->firstOrFail();
        $remaining = Decimal::from($row->reserved)->minus($reserved);
        if ($remaining->compareTo(Decimal::from('0')) < 0) {
            throw new LogicException('Budget reservation ledger is inconsistent.');
        }
        $row->update(['reserved' => (string) $remaining, 'actual' => (string) Decimal::from($row->actual)->plus($actual)]);
    }

    public function status(?string $period = null): array
    {
        $period ??= self::period();
        try {
            $config = self::config();
        } catch (ResearchFailure $failure) {
            return ['period' => $period, 'available' => false, 'reason' => $failure->reason->value];
        }
        $row = Budget::where('period', $period)->first();
        // A past period reports the envelope and subscriptions recorded then, not today's configuration.
        $historical = $row !== null && $period !== self::period();
        $envelope = $historical ? Decimal::from($row->envelope) : $config['envelope'];
        $fixed = $historical ? Decimal::from($row->fixed_costs) : $config['fixed'];
        $actual = Decimal::from($row?->actual ?? '0');
        $reserved = Decimal::from($row?->reserved ?? '0');
        $used = $fixed->plus($actual)->plus($reserved);
        $remaining = $envelope->minus($used);
        // Exhausted once the remainder cannot fund one bounded call at the maximum output size.
        $floor = $this->cost(0, (int) config('research.max_output_tokens'));

        return ['period' => $period, 'available' => $remaining->compareTo($floor) >= 0, 'reason' => null,
            'envelope' => (string) $envelope, 'fixed_costs' => (string) $fixed, 'actual' => (string) $actual, 'reserved' => (string) $reserved,
            'remaining' => (string) $remaining, 'used_pct' => (string) $used->multipliedBy(Decimal::from('100'))->dividedBy($envelope, 2),
            'reduced' => $used->compareTo($envelope->multipliedBy($config['reduce_at'])) >= 0, 'basis' => 'application_ledger_not_invoice'];
    }

    private function lockedRow(string $period, array $config): Budget
    {
        Budget::query()->insertOrIgnore(['period' => $period, 'envelope' => (string) $config['envelope'], 'fixed_costs' => (string) $config['fixed'],
            'reserved' => '0', 'actual' => '0', 'created_at' => now(), 'updated_at' => now()]);

        return Budget::where('period', $period)->lockForUpdate()->firstOrFail();
    }

    public static function money(?string $value): string
    {
        return $value === null ? 'Unknown' : '$'.Decimal::from($value)->rounded(4, RoundingMode::HALF_EVEN);
    }
}
