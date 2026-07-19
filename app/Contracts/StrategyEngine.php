<?php

namespace App\Contracts;

use App\Data\Research\EvaluationRequest;
use App\Data\Research\EvaluationResult;
use App\Models\EngineJob;

interface StrategyEngine
{
    public function submit(EvaluationRequest $request): EngineJob;

    public function result(EngineJob $job): ?EvaluationResult;
}
