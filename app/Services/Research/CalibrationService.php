<?php

namespace App\Services\Research;

class CalibrationService
{
    /**
     * @return array<string, mixed>
     */
    public function calibrateProbability(float $score): array
    {
        $probability = max(0.35, min(0.80, 0.35 + ($score * 0.45)));

        $tier = match (true) {
            $probability >= 0.72 => 'high',
            $probability >= 0.62 => 'medium',
            $probability >= 0.52 => 'low',
            default => 'very_low',
        };

        return [
            'probability' => round($probability, 4),
            'tier' => $tier,
        ];
    }
}
