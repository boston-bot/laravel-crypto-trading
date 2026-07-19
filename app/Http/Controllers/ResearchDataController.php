<?php

namespace App\Http\Controllers;

use App\Services\Research\ResearchStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResearchDataController extends Controller
{
    public function __construct(private readonly ResearchStatusService $research) {}

    public function dataHealth(): JsonResponse
    {
        return response()->json($this->research->dataHealth());
    }

    public function backtests(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->research->backtests($request->integer('limit', 25))]);
    }

    public function calibration(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->research->calibration($request->integer('limit', 10))]);
    }

    public function spreads(Request $request): JsonResponse
    {
        return response()->json($this->research->spreads($request->integer('hours', 24)));
    }
}
