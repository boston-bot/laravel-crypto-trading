<?php

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;

/** Display geometry only. Missing valuations break the line; money stays exact. */
final class PaperEquityChart
{
    public function build(array $series): array
    {
        $known = array_values(array_filter($series, fn ($row) => $row['equity'] !== null));
        $min = $max = null;
        foreach ($known as $row) {
            $value = Decimal::from($row['equity']);
            $min = $min === null || $value->compareTo($min) < 0 ? $value : $min;
            $max = $max === null || $value->compareTo($max) > 0 ? $value : $max;
        }
        $segments = $dots = $segment = [];
        foreach ($series as $index => $row) {
            if ($row['equity'] === null) {
                if ($segment) {
                    $segments[] = implode(' ', $segment);
                    $segment = [];
                }

                continue;
            }
            $x = count($series) > 1 ? Decimal::from((string) $index)->multipliedBy(Decimal::from('900'))->dividedBy(Decimal::from((string) (count($series) - 1)), 2)->plus(Decimal::from('30')) : Decimal::from('480');
            $span = $max->minus($min);
            $y = $span->compareTo(Decimal::from('0')) === 0 ? Decimal::from('110') : Decimal::from('195')->minus(Decimal::from($row['equity'])->minus($min)->multipliedBy(Decimal::from('170'))->dividedBy($span, 2));
            $segment[] = $x.','.$y;
            $dots[] = $row + ['x' => (string) $x, 'y' => (string) $y];
        }
        if ($segment) {
            $segments[] = implode(' ', $segment);
        }

        return ['series' => $series, 'segments' => $segments, 'dots' => $dots, 'known' => count($known), 'missing' => count($series) - count($known),
            'min' => $min === null ? null : (string) $min, 'max' => $max === null ? null : (string) $max];
    }
}
