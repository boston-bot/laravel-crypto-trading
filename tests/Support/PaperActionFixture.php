<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Models\PaperTrading\MarketInput;
use App\Services\MarketData\Alpaca\CorporateActions;

final class PaperActionFixture
{
    public static function event(array $changes = []): array
    {
        return array_replace_recursive(['id' => 'synthetic-action', 'type' => 'forward_splits', 'symbols' => ['symbol' => 'DEMO'],
            'dates' => ['process_date' => '2026-10-20', 'ex_date' => '2026-10-20'],
            'rates' => ['new_rate' => '2', 'old_rate' => '1'], 'flags' => ['special' => false, 'foreign' => false],
            'missing_fields' => [], 'adjustment_supported' => false], $changes);
    }

    public static function dividend(array $changes = []): array
    {
        return self::event(array_replace_recursive(['type' => 'cash_dividends', 'rates' => ['rate' => '0.25'],
            'dates' => ['record_date' => '2026-10-20', 'payable_date' => '2026-10-22']], $changes));
    }

    public static function input(McpConnection $connection, array $events, array $changes = []): MarketInput
    {
        return BrokerInputFixture::record($connection, $changes + ['corporate_action_reads.alpaca' => [
            'provider' => CorporateActions::URL, 'contract' => 'alpaca_actions_v1_process_date_all_quality', 'symbol' => 'DEMO',
            'start' => '2026-10-01', 'end' => '2026-11-01', 'date_basis' => 'process_date', 'data_quality' => 'all', 'types' => 'all',
            'source_as_of' => null, 'retrieved_at' => now()->toISOString(), 'complete' => true, 'pages' => 1,
            'error' => null, 'publication_delay_possible' => true, 'corporate_actions_clear' => null, 'events' => $events]]);
    }
}
