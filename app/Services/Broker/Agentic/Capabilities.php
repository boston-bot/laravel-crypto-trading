<?php

namespace App\Services\Broker\Agentic;

use App\Models\McpConnection;
use Opis\JsonSchema\Validator;

final class Capabilities
{
    public static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            return (object) self::canonical(get_object_vars($value));
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    public static function fingerprint(array $tool): string
    {
        return hash('sha256', json_encode(self::canonical($tool), JSON_THROW_ON_ERROR));
    }

    /** Only these locally reviewed meanings establish units. Remote prose never grants permissions. */
    public static function priceBookSemanticFingerprint(array $tool): string
    {
        $properties = $tool['outputSchema']['properties']['data']['properties']['books']['items']['properties'] ?? [];

        return self::fingerprint([
            'bid_quantity' => $properties['bids']['items']['properties']['quantity']['description'] ?? null,
            'ask_quantity' => $properties['asks']['items']['properties']['quantity']['description'] ?? null,
            'snapshot_time' => $properties['updated_at']['description'] ?? null,
        ]);
    }

    public function discover(McpConnection $connection, McpSession $session): array
    {
        $tools = [];
        $cursor = null;
        $seen = [];
        for ($page = 0; $page < config('robinhood_mcp.max_pages'); $page++) {
            $result = $session->listTools($cursor);
            foreach ($result['tools'] as $tool) {
                $name = $tool['name'] ?? '';
                if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $name) || isset($tools[$name])) {
                    throw new ReadFailure(ReadError::Malformed);
                }
                $schema = ['name' => $name, 'inputSchema' => $this->sanitize($tool['inputSchema']),
                    'outputSchema' => isset($tool['outputSchema']) ? $this->sanitize($tool['outputSchema']) : null];
                $tools[$name] = $schema + ['fingerprint' => self::fingerprint($schema)];
                if ($name === 'get_equity_price_book') {
                    $tools[$name]['semantic_fingerprint'] = self::priceBookSemanticFingerprint($tool);
                }
                if (count($tools) > 1000) {
                    throw new ReadFailure(ReadError::Incomplete);
                }
            }
            $cursor = $result['nextCursor'];
            if ($cursor === null) {
                break;
            }
            if ($cursor === '' || isset($seen[$cursor])) {
                throw new ReadFailure(ReadError::Incomplete);
            }
            $seen[$cursor] = true;
        }
        if ($cursor !== null) {
            throw new ReadFailure(ReadError::Incomplete);
        }
        ksort($tools);
        $changed = false;
        foreach (config('robinhood_mcp.operations') as $operation => $profile) {
            if (in_array($operation, ['classification', 'dividends'], true) && ! config('robinhood_mcp.reference_data_enabled')) {
                continue;
            }
            if (in_array($operation, ['tradability', 'price_book'], true) && ! config('robinhood_mcp.execution_inputs_enabled')) {
                continue;
            }
            if (in_array($operation, ['watchlists', 'watchlist_items'], true) && ! config('robinhood_mcp.watchlists_enabled')) {
                continue;
            }
            if (($profile['reviewed_read_only'] ?? false) && (($tools[$profile['tool']]['fingerprint'] ?? null) !== ($profile['fingerprint'] ?? null) ||
                (isset($profile['semantic_fingerprint']) && ($tools[$profile['tool']]['semantic_fingerprint'] ?? null) !== $profile['semantic_fingerprint']))) {
                $changed = true;
            }
        }
        $connection->update(['capability_manifest' => $tools, 'capability_fingerprint' => self::fingerprint($tools),
            'protocol_version' => $session->protocol(), 'capabilities_checked_at' => now(),
            'status' => $changed ? 'schema_changed' : 'connected', 'error_code' => $changed ? ReadError::SchemaChanged->value : null]);

        return $tools;
    }

    public function profile(Operation $operation, array $manifest): array
    {
        if (in_array($operation, [Operation::Classification, Operation::Dividends], true) && ! config('robinhood_mcp.reference_data_enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
        if (in_array($operation, [Operation::Tradability, Operation::PriceBook], true) && ! config('robinhood_mcp.execution_inputs_enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
        if (in_array($operation, [Operation::Watchlists, Operation::WatchlistItems], true) && ! config('robinhood_mcp.watchlists_enabled')) {
            throw new ReadFailure(ReadError::Disabled);
        }
        $profile = config('robinhood_mcp.operations.'.$operation->value);
        if (! is_array($profile) || ($profile['reviewed_read_only'] ?? false) !== true) {
            throw new ReadFailure(ReadError::Unsupported);
        }
        $tool = $manifest[$profile['tool']] ?? null;
        if (! $tool || ! hash_equals($profile['fingerprint'] ?? '', $tool['fingerprint'])) {
            throw new ReadFailure(ReadError::SchemaChanged);
        }

        if (isset($profile['semantic_fingerprint']) && ! hash_equals($profile['semantic_fingerprint'], $tool['semantic_fingerprint'] ?? '')) {
            throw new ReadFailure(ReadError::SchemaChanged);
        }

        return $profile + ['schema' => $tool];
    }

    public function validate(mixed $data, array $schema): void
    {
        try {
            // Only local references are accepted; no remote schema resolution/network.
            $this->sanitize($schema);
            $valid = (new Validator)->validate(json_decode(json_encode($data, JSON_THROW_ON_ERROR)), json_decode(json_encode($schema, JSON_THROW_ON_ERROR)))->isValid();
        } catch (\Throwable) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if (! $valid) {
            throw new ReadFailure(ReadError::Malformed);
        }
    }

    private function sanitize(mixed $value, bool $propertyMap = false): mixed
    {
        $wasObject = is_object($value);
        if ($wasObject) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (! $propertyMap && in_array($key, ['description', 'title', 'examples', 'default', '$comment'], true)) {
                continue;
            }
            if (! $propertyMap && in_array($key, ['$id', '$dynamicRef', '$recursiveRef', 'x-mcp-header'], true)) {
                // Header mappings cannot upgrade this client's fixed HTTP boundary.
                throw new ReadFailure(ReadError::Unsupported);
            }
            if (! $propertyMap && $key === '$ref' && (! is_string($item) || ! str_starts_with($item, '#'))) {
                throw new ReadFailure(ReadError::Unsupported);
            }
            $result[$key] = $this->sanitize($item, ! $propertyMap && in_array($key, ['properties', '$defs', 'definitions', 'patternProperties'], true));
        }

        return $result === [] && ($wasObject || ! array_is_list($value)) ? (object) [] : $result;
    }
}
