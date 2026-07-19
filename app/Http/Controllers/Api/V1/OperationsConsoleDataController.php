<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperatorAction;
use App\Services\Operations\OperationsConsoleQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsConsoleDataController extends Controller
{
    public function __construct(private readonly OperationsConsoleQueryService $query) {}

    public function overview(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->overview((int) $request->integer('window_days', 30))]);
    }

    public function strategies(): JsonResponse
    {
        return response()->json(['data' => $this->query->strategies()]);
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

    public function action(OperatorAction $operatorAction): JsonResponse
    {
        return response()->json(['data' => $operatorAction]);
    }
}
