<?php

namespace Tests\Unit\PaperTrading;

use App\Services\Crypto\CryptoParameters;
use App\Services\Crypto\CryptoPolicies;
use App\Services\PaperTrading\InstrumentSizing;
use App\Services\PaperTrading\PaperFillPrice;
use App\Services\PaperTrading\PortfolioEntryLimits;
use App\Services\PaperTrading\ProfileCalculator;
use App\Services\PaperTrading\RiskSizer;
use App\Services\PaperTrading\SignalRules;
use App\Services\PaperTrading\TradingCosts;
use App\Support\Mcp\Decimal;
use PHPUnit\Framework\TestCase;

class CryptoSharedRulesTest extends TestCase
{
    private function settings(): array
    {
        return CryptoPolicies::OWNER + ['slippage_bps' => '10', 'cost_schedule' => ['kind' => 'venue_notional_rate_v1', 'maker_rate' => '0.005', 'taker_rate' => '0.01']];
    }

    private function instrument(array $changes = []): array
    {
        return $changes + ['asset_class' => 'crypto', 'base_increment' => '0.00000001', 'base_min_size' => '0.00000001',
            'base_max_size' => '1000000000000', 'quote_min_size' => '1', 'quote_max_size' => '1000000000000'];
    }

    private function plannedSize(string $equity, string $cash, string $price, string $stop, array $i = []): array
    {
        return (new RiskSizer(new TradingCosts))->calculate(Decimal::from($equity), Decimal::from($cash), Decimal::from($price), Decimal::from($stop),
            $this->settings(), instrument: $this->instrument($i));
    }

    public function test_position_risk_and_cash_caps_include_fees_on_both_sides_without_equity_fees(): void
    {
        $size = $this->plannedSize('400', '400', '100', '95');
        $this->assertSame('1.00000000', $size['quantity']);
        $this->assertSame('20.000000000000000000', $size['risk_budget']);
        $this->assertSame('7.095000000000000000', $size['risk_per_unit']);
        $cash = $this->plannedSize('400', '50', '100', '95');
        $this->assertSame('0.49504950', $cash['quantity']);
        $risk = $this->plannedSize('400', '400', '100', '1');
        $q = Decimal::from($risk['quantity']);
        $this->assertLessThanOrEqual(0, $q->multipliedBy(Decimal::from($risk['risk_per_unit']))->compareTo(Decimal::from('20')));
        $this->assertSame('no_positive_risk_or_cash', $this->plannedSize('400', '0', '100', '95')['reason']);
    }

    public function test_non_power_of_ten_steps_maximum_sizes_and_minimum_order_values_are_enforced(): void
    {
        $this->assertSame('0.975', $this->plannedSize('400', '400', '101', '95', ['base_increment' => '0.025'])['quantity']);
        $this->assertSame('0.125', $this->plannedSize('400', '400', '101', '95', ['base_increment' => '0.025', 'base_max_size' => '0.13'])['quantity']);
        $this->assertSame('below_base_minimum', $this->plannedSize('400', '400', '101', '95', ['base_min_size' => '2'])['reason']);
        $this->assertSame('below_quote_minimum', $this->plannedSize('400', '400', '101', '95', ['quote_min_size' => '101'])['reason']);
        $this->assertSame('0', $this->plannedSize('0.000001', '0.000001', '100', '95')['quantity']);
        $this->assertSame('100.025', (string) InstrumentSizing::up(Decimal::from('100.001'), '0.025'));
        $this->assertSame('100.000', (string) InstrumentSizing::down(Decimal::from('100.001'), '0.025'));
    }

    public function test_cost_and_sizing_use_the_greater_role_rate_even_when_maker_is_more_expensive(): void
    {
        $s = $this->settings();
        $s['cost_schedule']['maker_rate'] = '0.02';
        $costs = new TradingCosts;
        foreach (['BUY', 'SELL'] as $side) {
            $this->assertSame('2.000000000000000000', (string) $costs->fee($s['cost_schedule'], $side, Decimal::from('1'), Decimal::from('100')));
            $this->assertSame('0.000000000000000001', (string) $costs->fee($s['cost_schedule'], $side, Decimal::from('0.000000000000000001'), Decimal::from('0.1')));
        }
        $size = (new RiskSizer($costs))->calculate(Decimal::from('400'), Decimal::from('50'), Decimal::from('100'), Decimal::from('95'), $s, instrument: $this->instrument());
        $this->assertSame('0.49019607', $size['quantity']);
        $this->assertSame('9.095000000000000000', $size['risk_per_unit']);
        $q = Decimal::from($size['quantity']);
        $this->assertLessThanOrEqual(0, $q->multipliedBy(Decimal::from('100'))->plus($costs->fee($s['cost_schedule'], 'BUY', $q, Decimal::from('100')))->compareTo(Decimal::from('50')));
    }

    public function test_tiny_coin_prices_and_eighteen_decimal_steps_are_not_zeroed(): void
    {
        $size = $this->plannedSize('400', '400', '0.000000000123456789', '0.0000000001', ['base_increment' => '0.000000000000000001']);
        $this->assertNull($size['reason']);
        $this->assertGreaterThan(0, Decimal::from($size['quantity'])->compareTo(Decimal::from('0')));
        $this->assertLessThanOrEqual(0, Decimal::from($size['quantity'])->multipliedBy(Decimal::from('0.000000000123456789'))->compareTo(Decimal::from('100')));
        $this->assertSame('0.000000000123580246', (string) PaperFillPrice::calculate('BUY', '0.000000000123456789', '10', 18));
    }

    public function test_daily_loss_and_position_limits_are_inclusive_and_unknown_marks_block_entries(): void
    {
        $this->assertNull(PortfolioEntryLimits::reason('380.000001', '400', 2, CryptoPolicies::OWNER));
        $this->assertSame('daily_loss_pause', PortfolioEntryLimits::reason('380', '400', 0, CryptoPolicies::OWNER));
        $this->assertSame('daily_loss_pause', PortfolioEntryLimits::reason('410', '400', 0, CryptoPolicies::OWNER, true));
        $this->assertSame('maximum_open_positions', PortfolioEntryLimits::reason('400', '400', 3, CryptoPolicies::OWNER));
        $this->assertSame('portfolio_valuation_unavailable', PortfolioEntryLimits::reason(null, '400', 0, CryptoPolicies::OWNER));
    }

    public function test_shared_profile_and_stops_keep_sub_cent_precision_and_crypto_has_no_earnings_blackout(): void
    {
        $bars = [];
        for ($i = 0; $i < 200; $i++) {
            $close = Decimal::from('0.00000001')->plus(Decimal::from((string) $i)->multipliedBy(Decimal::from('0.000000000001')));
            $bars[] = [(new \DateTimeImmutable('2026-01-01'))->modify("+{$i} days")->format('Y-m-d'), (string) $close,
                (string) $close->plus(Decimal::from('0.00000000001')), (string) $close->minus(Decimal::from('0.00000000001')), (string) $close, '1000000000'];
        }
        $p = CryptoParameters::current()['values'];
        $profile = (new ProfileCalculator)->compute($bars, $bars, $p, ['status' => 'pass', 'reason' => 'not_applicable_to_crypto']);
        $this->assertSame('complete', $profile['status']);
        $this->assertSame('breakout', $profile['setup']);
        $this->assertSame([], $profile['entry_blockers']);
        $this->assertGreaterThan(0, Decimal::from($profile['indicators']['atr'])->compareTo(Decimal::from('0')));
        $ask = (string) Decimal::from($profile['levels']['trigger'])->plus(Decimal::from('0.000000000001'));
        $entry = (new SignalRules)->entry($profile, $ask, $p);
        $this->assertSame('signal', $entry['status']);
        $this->assertGreaterThan(0, Decimal::from($entry['facts']['indicative_initial_stop'])->compareTo(Decimal::from('0')));
        $exit = (new SignalRules)->exit('0.000000000001', ['initial_stop' => '0.00000001', 'trailing_stop' => '0.00000001'], null, ['status' => 'unavailable'], $p);
        $this->assertSame(['signal', 'SELL', 'initial_stop_hit'], [$exit['status'], $exit['side'], $exit['reason']]);
    }
}
