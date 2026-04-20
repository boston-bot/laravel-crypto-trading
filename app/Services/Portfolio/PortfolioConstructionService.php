<?php

namespace App\Services\Portfolio;

class PortfolioConstructionService
{
    /**
     * @param  array<int, array<string, mixed>>  $rankedCandidates
     * @return array<int, array<string, mixed>>
     */
    public function selectCandidates(array $rankedCandidates, int $maxPositions = 4): array
    {
        if ($rankedCandidates === [] || $maxPositions <= 0) {
            return [];
        }

        usort($rankedCandidates, function (array $left, array $right): int {
            return ((float) ($right['composite_score'] ?? 0.0)) <=> ((float) ($left['composite_score'] ?? 0.0));
        });

        $selected = [];
        foreach ($rankedCandidates as $candidate) {
            if ((bool) ($candidate['entry_eligible'] ?? false) !== true) {
                continue;
            }

            $selected[] = $candidate;
            if (count($selected) >= $maxPositions) {
                break;
            }
        }

        return $selected;
    }
}
