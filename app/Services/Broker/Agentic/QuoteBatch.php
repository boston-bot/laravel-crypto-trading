<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;

/**
 * Reads exact-symbol quotes in bounded batches. A batch refused as malformed or incomplete is retried one
 * symbol at a time, so one unusable row cannot hide the others. Missing symbols stay unknown.
 */
final class QuoteBatch
{
    /** @return array{quotes: array<string, ReadResult>, failures: array<string, string>} */
    public static function read(BrokerGateway $gateway, McpConnection $connection, array $symbols): array
    {
        $size = max(1, (int) config('robinhood_mcp.operations.quotes.max_batch_symbols', 1));
        $quotes = [];
        $failures = [];
        foreach (array_chunk(array_values(array_unique($symbols)), $size) as $chunk) {
            try {
                $found = self::split(self::fetch($gateway, $connection, $chunk), $chunk);
            } catch (ReadFailure $failure) {
                if (count($chunk) > 1 && in_array($failure->reason, [ReadError::Incomplete, ReadError::Malformed], true)) {
                    foreach ($chunk as $symbol) {
                        try {
                            $quotes += self::split(self::fetch($gateway, $connection, [$symbol]), [$symbol]);
                            if (! isset($quotes[$symbol])) {
                                $failures[$symbol] = 'incomplete_quote';
                            }
                        } catch (ReadFailure $single) {
                            $failures[$symbol] = $single->reason->value;
                        }
                    }

                    continue;
                }
                foreach ($chunk as $symbol) {
                    $failures[$symbol] = $failure->reason->value;
                }

                continue;
            }
            $quotes += $found;
            foreach (array_diff($chunk, array_keys($found)) as $symbol) {
                $failures[$symbol] = 'incomplete_quote';
            }
        }

        return ['quotes' => $quotes, 'failures' => $failures];
    }

    private static function fetch(BrokerGateway $gateway, McpConnection $connection, array $chunk): ReadResult
    {
        $read = $gateway->read($connection, Operation::Quotes, count($chunk) === 1 ? ['symbol' => $chunk[0]] : ['symbols' => $chunk]);
        if (! $read->complete || $read->continuation !== null) {
            throw new ReadFailure($read->incompleteReason ?? ReadError::Incomplete);
        }

        return $read;
    }

    /** @return array<string, ReadResult> one single-record result per requested symbol found exactly once */
    private static function split(ReadResult $read, array $chunk): array
    {
        $found = [];
        $duplicate = [];
        foreach ($read->records as $record) {
            $symbol = $record->fields['symbol'] ?? null;
            if ($record->operation !== Operation::Quotes || ! in_array($symbol, $chunk, true)) {
                continue;
            }
            if (isset($found[$symbol])) {
                $duplicate[$symbol] = true;
            }
            $found[$symbol] = new ReadResult($read->source, $read->retrievedAt, [$record], true, null, null, $read->pages, $read->coverage);
        }

        return array_diff_key($found, $duplicate);
    }
}
