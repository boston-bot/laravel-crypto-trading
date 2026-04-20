<?php

namespace App\Services\Audit;

use App\Models\TradeDecision;

class TradeReportService
{
    /**
     * @return array<string, mixed>
     */
    public function dailySummary(): array
    {
        $base = TradeDecision::query()
            ->whereDate('created_at', today());

        return [
            'date' => today()->toDateString(),
            'total_decisions' => (clone $base)->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'submitted' => (clone $base)->where('status', 'submitted')->count(),
            'filled' => (clone $base)->where('status', 'filled')->count(),
            'blocked' => (clone $base)->where('status', 'blocked_by_policy')->count(),
        ];
    }
}

