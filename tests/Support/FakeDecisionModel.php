<?php

namespace Tests\Support;

use App\Services\Research\ModelRequest;
use App\Services\Research\ModelResponse;
use App\Services\Research\ResearchModel;

final class FakeDecisionModel implements ResearchModel
{
    public array $requests = [];

    public ?\Closure $respond = null;

    public ?string $log = null;

    public int $sleepMs = 0;

    public function complete(ModelRequest $request): ModelResponse
    {
        $this->requests[] = $request;
        if ($this->log) {
            file_put_contents($this->log, "call\n", FILE_APPEND | LOCK_EX);
        }
        if ($this->sleepMs) {
            usleep($this->sleepMs * 1000);
        }

        return $this->respond ? ($this->respond)($request) : self::answer($request);
    }

    public static function context(ModelRequest $request): array
    {
        preg_match('/^<untrusted_data>(.*)<\/untrusted_data>$/s', $request->input, $match);

        return json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
    }

    public static function answer(ModelRequest $request, ?callable $mutate = null): ModelResponse
    {
        $context = self::context($request);
        $data = ['summary' => 'Synthetic paper review.', 'decisions' => array_map(fn ($symbol) => [
            'symbol' => $symbol, 'action' => 'WAIT', 'target_weight_pct' => null, 'rationale' => 'Synthetic fixture evidence is insufficient.',
            'source_ids' => array_values(array_slice(array_column(array_filter($context['sources'], fn ($s) => $s['symbol'] === $symbol), 'id'), 0, 1)),
            'horizon' => 'weeks', 'invalidation' => 'Review on new evidence.', 'risks' => ['Synthetic uncertainty.'],
            'expires_at' => $context['expires_at'],
        ], array_keys($context['subjects']))];
        if ($mutate) {
            $data = $mutate($data);
        }

        return new ModelResponse('completed', json_encode($data, JSON_THROW_ON_ERROR), 1000, 200, $request->model);
    }
}
