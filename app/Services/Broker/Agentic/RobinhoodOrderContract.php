<?php

declare(strict_types=1);

namespace App\Services\Broker\Agentic;

/**
 * Reviewed facts about Robinhood agentic equity orders, from the full `place_equity_order` and `review_equity_order`
 * descriptions (read-only `tools/list`, October 19, 2026). Neither tool is callable from this application.
 * A description change invalidates these facts until they are reviewed again.
 */
final class RobinhoodOrderContract
{
    public const REVIEWED_ON = '2026-10-19';

    /** sha256 of the reviewed description text for each order tool. */
    public const DESCRIPTION_SHA256 = [
        'place_equity_order' => '02d7bb16514d2664b62996e5248264e4fad18e9407bde355adad8c3cc2d2357a',
        'review_equity_order' => 'ba972f915d507ae4cb8d03e4f3a06af89e0b6051c1834885f4b46556878da729',
    ];

    /** "Fractional shares: only on type=market with market_hours=regular_hours, eligible accounts, up to 6 decimal places, no short sells." */
    public const FRACTIONAL_ORDER_TYPES = ['market'];

    public const FRACTIONAL_MARKET_HOURS = 'regular_hours';

    public const FRACTIONAL_MAX_DECIMALS = 6;

    /** Robinhood's published minimum value for a fractional order (support article "Fractional shares"). */
    public const FRACTIONAL_MIN_NOTIONAL = '1.00';

    public static function allowsFractional(string $type, string $marketHours = 'regular_hours'): bool
    {
        return in_array($type, self::FRACTIONAL_ORDER_TYPES, true) && $marketHours === self::FRACTIONAL_MARKET_HOURS;
    }
}
