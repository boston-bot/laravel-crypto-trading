<?php

declare(strict_types=1);

namespace App\Services\PaperTrading;

use App\Support\Mcp\Decimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Venue constraints for the shared sizer. Increment need not be a power of ten. */
final class InstrumentSizing
{
    public static function down(Decimal $value, string $increment): Decimal
    {
        $step = Decimal::from($increment);
        if ($step->compareTo(Decimal::from('0')) <= 0) {
            throw new InvalidArgumentException('Increment must be positive.');
        }

        return $value->dividedBy($step, 0, RoundingMode::FLOOR)->multipliedBy($step);
    }

    public static function up(Decimal $value, string $increment): Decimal
    {
        $step = Decimal::from($increment);
        if ($step->compareTo(Decimal::from('0')) <= 0) {
            throw new InvalidArgumentException('Increment must be positive.');
        }

        return $value->dividedBy($step, 0, RoundingMode::CEILING)->multipliedBy($step);
    }
}
