<?php

namespace App\Services\MarketData;

use App\Models\SentimentObservation;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Collection;

class SentimentIngestionService
{
    public function __construct(private readonly HttpFactory $http) {}

    public function refresh(int $limit = 0): int
    {
        $response = $this->http->acceptJson()->timeout(10)->retry(3, 200)->get(
            (string) config('research.sentiment.url'),
            ['limit' => max(0, $limit), 'format' => 'json'],
        )->throw()->json();
        $rows = collect((array) ($response['data'] ?? []))->sortBy('timestamp')->values();
        $stored = 0;
        foreach ($rows as $row) {
            $publishedAt = now()->createFromTimestampUTC((int) $row['timestamp']);
            $value = max(0, min(100, (int) $row['value']));
            $history = SentimentObservation::query()->where('source', 'alternative_me')
                ->where('published_at', '<', $publishedAt)->orderByDesc('published_at')->limit(90)->pluck('raw_value');
            $observation = SentimentObservation::query()->firstOrNew([
                'source' => 'alternative_me', 'published_at' => $publishedAt,
            ]);
            $observation->fill([
                'first_seen_at' => $observation->first_seen_at ?? now(), 'raw_value' => $value,
                'normalized_score' => ($value - 50) / 50,
                'change_1d' => $this->change($history, $value, 1),
                'change_7d' => $this->change($history, $value, 7),
                'zscore_30d' => $this->zscore($history->take(30), $value),
                'zscore_90d' => $this->zscore($history, $value),
                'classification' => $row['value_classification'] ?? null,
                'raw_json' => [...$row, 'attribution' => 'Alternative.me Crypto Fear & Greed Index'],
            ])->save();
            $stored++;
        }

        return $stored;
    }

    private function change(Collection $history, int $current, int $days): ?float
    {
        $prior = $history->get($days - 1);

        return $prior === null ? null : $current - (float) $prior;
    }

    private function zscore(Collection $history, int $current): ?float
    {
        if ($history->count() < 10) {
            return null;
        }
        $average = (float) $history->avg();
        $std = (float) sqrt($history->map(fn ($value): float => ((float) $value - $average) ** 2)->avg());

        return $std > 0 ? ($current - $average) / $std : 0.0;
    }
}
