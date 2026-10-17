<?php

namespace Tests\Support;

/** Synthetic research configuration. The credential is a placeholder and the model is always faked. */
final class ResearchFixture
{
    public static function enable(array $overrides = []): void
    {
        config(array_merge(['research.enabled' => true, 'research.provider' => 'openai', 'research.model' => 'synthetic-model', 'research.api_key' => 'synthetic-key',
            'research.input_cost_per_million' => '1.00', 'research.output_cost_per_million' => '4.00', 'research.fixed_monthly_costs' => '0.00',
            'research.monthly_envelope' => '100.00'], $overrides));
    }

    public static function evidence(string $symbol = 'SYNTH', array $overrides = []): array
    {
        return array_merge(['symbol' => $symbol, 'kind' => 'article', 'label' => 'Synthetic fixture article', 'url' => 'https://example.test/synthetic',
            'published_at' => now()->subHour()->toIso8601String(), 'excerpt' => 'Synthetic excerpt describing a fixture-only company development.'], $overrides);
    }
}
