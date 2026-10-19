<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;
use DateTimeImmutable;
use Illuminate\Support\Arr;

final class RobinhoodGateway implements BrokerGateway
{
    private const SYMBOL = '/^[A-Z][A-Z0-9.\-]{0,14}$/D';

    /** Data-level failures keep a scoped session; anything else may mean the session or token is unusable. */
    private const SESSION_SAFE = [ReadError::Stale, ReadError::Incomplete, ReadError::Unsupported, ReadError::Disabled];

    /** @var array<int, array{session: McpSession, manifest: array, generation: int, opened_at: int}|null> */
    private array $shared = [];

    public function __construct(private SessionFactory $sessions, private Capabilities $capabilities, private BrokerBackoff $backoff) {}

    /**
     * Reads inside $work share one MCP session and one catalog check. The session is reopened after
     * `session_max_seconds`, after an authorization change, or after a session-level failure.
     */
    public function within(McpConnection $connection, callable $work): mixed
    {
        if (array_key_exists($connection->id, $this->shared)) {
            return $work();
        }
        $this->shared[$connection->id] = null;
        try {
            return $work();
        } finally {
            $this->release($connection->id);
            unset($this->shared[$connection->id]);
        }
    }

    public function discover(McpConnection $connection): array
    {
        if ($remaining = $this->backoff->remaining($connection->id)) {
            throw new ReadFailure(ReadError::RateLimited, $remaining);
        }
        $session = $this->sessions->open($connection);
        try {
            return $this->capabilities->discover($connection, $session);
        } catch (ReadFailure $failure) {
            $this->recordError($connection, $failure);
            if ($failure->reason === ReadError::RateLimited) {
                throw new ReadFailure(ReadError::RateLimited, $this->backoff->record($connection->id, $failure->retryAfter));
            }
            throw $failure;
        } finally {
            $session->close();
        }
    }

    /**
     * `symbols` (a list) reads several exact symbols in one call where the reviewed profile allows a batch.
     * Records for unrequested symbols are skipped or refused as the profile specifies; missing symbols are left
     * for the caller to report as unknown.
     */
    public function read(McpConnection $connection, Operation $operation, array $filters = []): ReadResult
    {
        $connection->refresh();
        $generation = $connection->auth_generation;
        $boundAccount = $connection->bound_account_id;
        if (in_array($operation, [Operation::Watchlists, Operation::WatchlistItems], true) && ! $boundAccount) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }
        $scoped = in_array($operation, [Operation::Portfolio, Operation::Positions, Operation::Orders, Operation::CashFlows, Operation::History, Operation::Tradability], true);
        if ($scoped && ! $connection->bound_account_id) {
            throw new ReadFailure(ReadError::AccountMismatch);
        }
        // Missing configuration and invalid batches are refused before opening a network connection.
        $preflight = $this->capabilities->profile($operation, $connection->capability_manifest ?? []);
        $symbols = null;
        if (array_key_exists('symbols', $filters)) {
            $symbols = $filters['symbols'];
            unset($filters['symbols']);
            $max = (int) ($preflight['max_batch_symbols'] ?? 0);
            if ($max < 2 || isset($filters['symbol']) || ! isset($preflight['filters']['symbol']) || ! in_array('symbol', $preflight['array_filters'] ?? [], true)
                || ! is_array($symbols) || ! array_is_list($symbols) || $symbols === [] || count($symbols) > $max) {
                throw new ReadFailure(ReadError::Malformed);
            }
            foreach ($symbols as $symbol) {
                if (! is_string($symbol) || ! preg_match(self::SYMBOL, $symbol)) {
                    throw new ReadFailure(ReadError::Malformed);
                }
            }
            if (count(array_unique($symbols)) !== count($symbols)) {
                throw new ReadFailure(ReadError::Malformed);
            }
        }
        if ($remaining = $this->backoff->remaining($connection->id)) {
            throw new ReadFailure(ReadError::RateLimited, $remaining);
        }
        $shared = array_key_exists($connection->id, $this->shared);
        $session = null;
        $records = [];
        $cursor = null;
        $seen = [];
        $pages = 0;
        $retrieved = new DateTimeImmutable;
        try {
            if ($shared) {
                [$session, $manifest] = $this->scopedSession($connection);
            } else {
                $session = $this->sessions->open($connection);
                $manifest = $this->capabilities->discover($connection, $session);
            }
            $profile = $this->capabilities->profile($operation, $manifest);
            $arguments = $profile['arguments'] ?? [];
            if (isset($profile['required_filter']) && empty($filters[$profile['required_filter']]) && ! ($symbols && $profile['required_filter'] === 'symbol')) {
                throw new ReadFailure(ReadError::Malformed);
            }
            if (isset($filters['symbol']) && ! preg_match(self::SYMBOL, $filters['symbol'])) {
                throw new ReadFailure(ReadError::Malformed);
            }
            if ($symbols) {
                Arr::set($arguments, $profile['filters']['symbol'], $symbols);
            }
            foreach ($filters as $key => $value) {
                if (! in_array($key, ['symbol', 'instrument_id', 'order_id', 'list_id'], true) || ! isset($profile['filters'][$key]) || ! is_string($value) || $value === '' || strlen($value) > 128) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                Arr::set($arguments, $profile['filters'][$key], in_array($key, $profile['array_filters'] ?? [], true) ? [$value] : $value);
            }
            if ($scoped && empty($profile['account_argument'])) {
                throw new ReadFailure(ReadError::Unsupported);
            }
            if (isset($profile['account_argument'])) {
                $arguments[$profile['account_argument']] = $connection->bound_account_id;
            }
            $pagination = $profile['pagination'] ?? null;
            if (! in_array($pagination['kind'] ?? null, ['cursor', 'single'], true)) {
                throw new ReadFailure(ReadError::Unsupported);
            }
            if ($pagination['kind'] === 'cursor' && (empty($pagination['argument']) || empty($pagination['next']))) {
                throw new ReadFailure(ReadError::Unsupported);
            }
            do {
                if ($pages >= config('robinhood_mcp.max_pages')) {
                    return new ReadResult(TrustedHttp::RESOURCE, $retrieved, $records, false, $cursor, ReadError::Incomplete, $pages, $profile['coverage'] ?? 'configured_operation');
                }
                $pageArguments = $arguments;
                if ($cursor !== null) {
                    $pageArguments[$pagination['argument']] = $cursor;
                }
                $this->capabilities->validate((object) $pageArguments, $profile['schema']['inputSchema']);
                $body = $session->call($profile['tool'], $pageArguments);
                if ($profile['schema']['outputSchema']) {
                    $this->capabilities->validate(array_is_list($body) ? $body : (object) $body, $profile['schema']['outputSchema']);
                }
                $rows = ($profile['records'] ?? 'records') === '$' ? $body : Arr::get($body, $profile['records'] ?? 'records');
                if ($rows === null) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                if (($profile['records_object'] ?? false) && is_array($rows) && ! array_is_list($rows)) {
                    $rows = [$rows];
                }
                if (! is_array($rows) || ! array_is_list($rows)) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                if (count($rows) + count($records) > config('robinhood_mcp.max_records')) {
                    return new ReadResult(TrustedHttp::RESOURCE, $retrieved, $records, false, $cursor, ReadError::Incomplete, $pages, $profile['coverage'] ?? 'configured_operation');
                }
                if (isset($profile['total_count']) && Arr::get($body, $profile['total_count']) !== count($rows)) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                $pageAsOf = $this->timestamp(Arr::get($body, $profile['source_as_of'] ?? 'source_as_of'));
                $normalized = [];
                foreach ($rows as $row) {
                    if (! is_array($row)) {
                        throw new ReadFailure(ReadError::Malformed);
                    }
                    $fields = [];
                    foreach ($profile['fields'] ?? [] as $local => $remote) {
                        $fields[$local] = Arr::get($row, $remote);
                        if (isset($profile['value_maps'][$local]) && $fields[$local] !== null) {
                            if (! is_string($fields[$local]) || ! array_key_exists($fields[$local], $profile['value_maps'][$local])) {
                                throw new ReadFailure(ReadError::Malformed);
                            }
                            $fields[$local] = $profile['value_maps'][$local][$fields[$local]];
                        }
                    }
                    if (($profile['normalizer'] ?? null) === 'robinhood_accounts_v1') {
                        if ($operation !== Operation::Accounts) {
                            throw new ReadFailure(ReadError::Unsupported);
                        }
                        $fields = RobinhoodAccountNormalizer::fields($row);
                    }
                    if (($profile['normalizer'] ?? null) === 'robinhood_v1') {
                        $fields = RobinhoodReadNormalizer::fields($operation, $row, $boundAccount);
                    }
                    if ($operation === Operation::WatchlistItems) {
                        $fields += ['list_id' => $filters['list_id'], 'watchlist_scope' => 'request_list_id'];
                    }
                    if (($profile['exact_symbol'] ?? false) && ! in_array($fields['symbol'] ?? null, $symbols ?? [$filters['symbol'] ?? null], true)) {
                        if ($profile['strict_symbol'] ?? false) {
                            throw new ReadFailure(ReadError::Incomplete);
                        }

                        continue;
                    }
                    $asOf = isset($profile['record_source_as_of']) ? $this->timestamp(Arr::get($row, $profile['record_source_as_of'])) : $pageAsOf;
                    if ($operation === Operation::Quotes && ($profile['normalizer'] ?? null) === 'robinhood_v1') {
                        $extendedTime = $this->timestamp($fields['extended_last_as_of']);
                        $regularTime = $this->timestamp($fields['regular_last_as_of']);
                        if ($extendedTime && $fields['extended_last'] !== null && (! $regularTime || $extendedTime > $regularTime)) {
                            $fields['last'] = $fields['extended_last'];
                            $fields['last_as_of'] = $fields['extended_last_as_of'];
                            $fields['last_session'] = 'extended';
                        }
                        $times = [];
                        foreach (['bid_as_of', 'ask_as_of', 'last_as_of'] as $key) {
                            $time = $this->timestamp($fields[$key]);
                            $fields[$key] = $time?->format(DATE_ATOM);
                            if ($time) {
                                $times[] = $time;
                            }
                        }
                        $asOf = count($times) === 3 ? min($times) : null;
                    }
                    if ($asOf && $asOf->getTimestamp() < now()->timestamp - config('robinhood_mcp.max_age_seconds') && in_array($operation, [Operation::Portfolio, Operation::Positions, Operation::Quotes], true)) {
                        throw new ReadFailure(ReadError::Stale);
                    }
                    $record = BrokerRecord::from($operation, $fields, $asOf);
                    if ($scoped && ($record->fields['account_id'] ?? null) !== $connection->bound_account_id) {
                        throw new ReadFailure(ReadError::AccountMismatch);
                    }
                    $id = $record->fields['id'] ?? (isset($record->fields['symbol']) ? $record->fields['symbol'].':'.($record->fields['position_type'] ?? '') : null);
                    if ($id !== null) {
                        if (isset($seen['record:'.$id])) {
                            throw new ReadFailure(ReadError::Incomplete);
                        }
                        $seen['record:'.$id] = true;
                    }
                    $normalized[] = $record;
                }
                $next = $pagination['kind'] === 'single' ? null : Arr::get($body, $pagination['next']);
                // The reviewed mapping must designate an explicit terminal null, not a missing field.
                if ($pagination['kind'] === 'cursor' && ! ($pagination['missing_is_terminal'] ?? false) && ! Arr::has($body, $pagination['next'])) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                if (($pagination['empty_is_terminal'] ?? false) && $next === '') {
                    $next = null;
                }
                if (is_string($next) && ($pagination['broker_cursor'] ?? false)) {
                    $next = BrokerCursor::normalize($next, $boundAccount);
                }
                if ($next !== null && (! is_string($next) || $next === '' || isset($seen['cursor:'.$next]))) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                if (isset($profile['complete_field']) && Arr::get($body, $profile['complete_field']) !== true) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
                $records = array_merge($records, $normalized);
                $cursor = $next;
                if ($cursor !== null) {
                    $seen['cursor:'.$cursor] = true;
                }
                $pages++;
            } while ($cursor !== null);
            if (($profile['exact_symbol'] ?? false) && $symbols === null && count($records) !== 1) {
                throw new ReadFailure(ReadError::Incomplete);
            }
            $current = $connection->fresh();
            if ($current->auth_generation !== $generation || $current->bound_account_id !== $boundAccount) {
                throw new ReadFailure(ReadError::AccountMismatch);
            }

            return new ReadResult(TrustedHttp::RESOURCE, new DateTimeImmutable, $records, true, null, null, $pages, $profile['coverage'] ?? 'configured_operation');
        } catch (ReadFailure $failure) {
            $this->recordError($connection, $failure);
            if ($failure->reason === ReadError::RateLimited) {
                $failure = new ReadFailure(ReadError::RateLimited, $this->backoff->record($connection->id, $failure->retryAfter));
            }
            if ($shared && ! in_array($failure->reason, self::SESSION_SAFE, true)) {
                $this->release($connection->id);
            }
            if (($pages > 0 || $failure->reason === ReadError::Incomplete) && in_array($failure->reason, [ReadError::Timeout, ReadError::Transport, ReadError::RateLimited, ReadError::Incomplete], true)) {
                return new ReadResult(TrustedHttp::RESOURCE, $retrieved, $records, false, $cursor, $failure->reason, $pages, $profile['coverage'] ?? 'configured_operation');
            }
            throw $failure;
        } finally {
            if (! $shared) {
                $session?->close();
            }
        }
    }

    /** @return array{0: McpSession, 1: array} */
    private function scopedSession(McpConnection $connection): array
    {
        $scope = $this->shared[$connection->id] ?? null;
        $maxAge = (int) config('robinhood_mcp.session_max_seconds');
        if ($scope !== null && $scope['generation'] === $connection->auth_generation && $scope['opened_at'] > now()->getTimestamp() - $maxAge) {
            return [$scope['session'], $scope['manifest']];
        }
        $this->release($connection->id);
        // The token must outlive the session, so it is refreshed up front instead of expiring mid-scope.
        $session = $this->sessions->open($connection, $maxAge + 30);
        try {
            $manifest = $this->capabilities->discover($connection, $session);
        } catch (\Throwable $error) {
            $this->closeQuietly($session);
            throw $error;
        }
        $this->shared[$connection->id] = ['session' => $session, 'manifest' => $manifest,
            'generation' => $connection->auth_generation, 'opened_at' => now()->getTimestamp()];

        return [$session, $manifest];
    }

    private function release(int $connectionId): void
    {
        $session = $this->shared[$connectionId]['session'] ?? null;
        if (array_key_exists($connectionId, $this->shared)) {
            $this->shared[$connectionId] = null;
        }
        if ($session) {
            $this->closeQuietly($session);
        }
    }

    private function closeQuietly(McpSession $session): void
    {
        try {
            $session->close();
        } catch (\Throwable) {
            // Closing is best effort; the server expires abandoned sessions.
        }
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new ReadFailure(ReadError::Malformed);
        }
        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
        } catch (\Throwable) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if ($timestamp->getTimestamp() > now()->timestamp + 60) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
            throw new ReadFailure(ReadError::Malformed);
        }

        return $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    private function recordError(McpConnection $connection, ReadFailure $failure): void
    {
        $connection->update(['error_code' => $failure->reason->value] + match ($failure->reason) {
            ReadError::Authentication => ['status' => 'reconnect_required'],
            ReadError::SchemaChanged => ['status' => 'schema_changed'],
            ReadError::AccountMismatch => ['status' => 'account_mismatch'],
            default => [],
        });
    }
}
