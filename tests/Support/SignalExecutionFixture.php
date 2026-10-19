<?php

namespace Tests\Support;

use App\Services\PaperTrading\SignalExecutionPolicies;
use Illuminate\Support\Str;

final class SignalExecutionFixture
{
    public static function seed(): array
    {
        $state = StrategySignalFixture::seed();
        [$c] = $state;
        config(['paper.signal_handoff_enabled' => true, 'paper.real_data_fills_enabled' => true]);
        app(SignalExecutionPolicies::class)->apply($c, $c->user_id, 0, (string) Str::uuid(), SignalExecutionPolicies::DEFAULTS);
        self::quote($c);

        return $state;
    }

    public static function quote($c, string $bid = '101.50', string $ask = '101.60', string $size = '100', array $patch = [])
    {
        return StrategySignalFixture::quote($c, $patch + ['quote.bid' => $bid, 'quote.ask' => $ask,
            'execution_reads.price_book.fields.book_bid' => $bid, 'execution_reads.price_book.fields.book_ask' => $ask,
            'execution_reads.price_book.fields.book_bid_quantity' => $size, 'execution_reads.price_book.fields.book_ask_quantity' => $size]);
    }
}
