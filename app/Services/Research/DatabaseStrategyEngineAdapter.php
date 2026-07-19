<?php

namespace App\Services\Research;

use App\Contracts\StrategyEngine;
use App\Data\Research\EvaluationRequest;
use App\Data\Research\EvaluationResult;
use App\Models\EngineJob;

class DatabaseStrategyEngineAdapter implements StrategyEngine
{
    public function __construct(private readonly EngineJobService $jobs) {}

    public function submit(EvaluationRequest $request): EngineJob
    {
        return $this->jobs->enqueueEvaluation($request);
    }

    public function result(EngineJob $job): ?EvaluationResult
    {
        $result = $job->result()->first();

        return $result ? EvaluationResult::fromModel($result) : null;
    }
}
