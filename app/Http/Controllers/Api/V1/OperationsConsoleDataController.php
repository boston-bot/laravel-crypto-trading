<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AssetEvaluation;
use App\Models\OperatorAction;
use App\Models\StrategyExperiment;
use App\Services\Operations\OperationsConsoleQueryService;
use App\Services\Operations\ResearchLabQueryService;
use App\Services\Operations\StrategyTransparencyQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsConsoleDataController extends Controller
{
    public function __construct(
        private readonly OperationsConsoleQueryService $query,
        private readonly StrategyTransparencyQueryService $transparency,
        private readonly ResearchLabQueryService $researchLab,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->overview((int) $request->integer('window_days', 30))]);
    }

    public function strategies(): JsonResponse
    {
        return response()->json(['data' => $this->query->strategies()]);
    }

    public function latestDecision(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->transparency->latest(
            $request->integer('asset_id') ?: null,
            $request->string('cycle_id')->toString() ?: null,
        )]);
    }

    public function decision(AssetEvaluation $assetEvaluation): JsonResponse
    {
        return response()->json(['data' => $this->transparency->detail($assetEvaluation)]);
    }

    public function assets(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->assets((int) $request->integer('window_days', 30))]);
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->activity($request->string('cycle_id')->toString() ?: null)]);
    }

    public function paper(): JsonResponse
    {
        return response()->json(['data' => $this->query->paper()]);
    }

    public function operations(): JsonResponse
    {
        return response()->json(['data' => $this->query->operations()]);
    }

    public function research(): JsonResponse
    {
        return response()->json(['data' => $this->query->research()]);
    }

    public function experiments(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->researchLab->experiments(
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 10),
        )]);
    }

    public function experiment(StrategyExperiment $strategyExperiment): JsonResponse
    {
        return response()->json(['data' => $this->researchLab->experiment($strategyExperiment)]);
    }

    public function researchRuns(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->researchLab->runs(
            $request->integer('experiment_id') ?: null,
            (int) $request->integer('page', 1),
            (int) $request->integer('per_page', 20),
        )]);
    }

    public function action(OperatorAction $operatorAction): JsonResponse
    {
        return response()->json(['data' => $operatorAction]);
    }
}
