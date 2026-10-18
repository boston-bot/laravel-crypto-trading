<?php

namespace Tests\Support;

use App\Models\McpConnection;
use App\Services\PaperTrading\DecisionInputs;
use Carbon\CarbonImmutable;

/** Synthetic eligibility and quotes, never a claim of reviewed real coverage. */
final class FakeDecisionInputs implements DecisionInputs
{
    public ?\Closure $mutate = null;

    public function load(McpConnection $connection, CarbonImmutable $cutoff): array
    {
        $time = $cutoff->utc()->format(DATE_ATOM);
        $input = ['coverage' => 'synthetic_fixture', 'subjects' => ['SYNTH' => ['eligibility' => 'eligible',
            'quote' => ['bid' => '100.00', 'ask' => '100.01', 'source_as_of' => $time, 'retrieved_at' => $time]]],
            'sources' => [['id' => 'S1', 'symbol' => 'SYNTH', 'kind' => 'note', 'as_of' => $time, 'retrieved_at' => $time, 'excerpt' => 'Synthetic company event.']],
            'source_map' => ['S1' => ['symbol' => 'SYNTH', 'record_id' => 999, 'as_of' => $time]], 'positions' => null, 'positions_status' => 'not_implemented'];

        return $this->mutate ? ($this->mutate)($input) : $input;
    }
}
