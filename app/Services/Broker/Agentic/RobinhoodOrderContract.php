<?php

declare(strict_types=1);

namespace App\Services\Broker\Agentic;

/**
 * Reviewed facts about Robinhood agentic equity orders, from the full order and approval tool descriptions (read-only
 * `tools/list`, October 19 and 25, 2026). No order or approval-decision tool is callable from this application.
 * A description change invalidates these facts until they are reviewed again.
 */
final class RobinhoodOrderContract
{
    public const REVIEWED_ON = '2026-10-19';

    /** Description-level tools/list re-review; the two order descriptions were byte-identical to October 19. */
    public const RECONFIRMED_ON = '2026-10-25';

    /** sha256 of the reviewed description text for each order, approval and order-history tool. */
    public const DESCRIPTION_SHA256 = [
        'place_equity_order' => '02d7bb16514d2664b62996e5248264e4fad18e9407bde355adad8c3cc2d2357a',
        'review_equity_order' => 'ba972f915d507ae4cb8d03e4f3a06af89e0b6051c1834885f4b46556878da729',
        'cancel_equity_order' => 'b32dda3969b2acea70516848fa7a92cef79cbc6400115ac8977afd04bc753d85',
        'get_equity_orders' => 'f1b110aa226e6b6e50971f64d9e7b25168ddd167848f80f574bfad14f6986aa7',
        'get_trade_approval_setting' => 'b7ceb94f478ffbc0bbcc1a55157545ad48ff1511fc80fed9596961db229a8a7c',
        'get_trade_approvals' => '9e08a452cbc679f22ae0f5b4f278a7b07a963d1af91b9215dfd95dfba40e5bae',
        'decline_trade_approval' => '0f3602851f124f2932c4ed9eb3720f7288cc8626bff671aaf2d137c0af1344b1',
    ];

    /** Capabilities::fingerprint of each write tool's sanitized schemas. A change invalidates a future write gateway. */
    public const SCHEMA_FINGERPRINT = [
        'place_equity_order' => '247eeea504d8189923e523a6934dbb76bad75ac7bc406b3a50d482c7aacf500f',
        'review_equity_order' => '6694eb3ac23ef79878edade77d7a51153b4679bf2979df8737a465d7f1a289f1',
        'cancel_equity_order' => '55dfd311271252765ef05622e39b5e46250f598e21d82856b870d8cced80af67',
        'decline_trade_approval' => '4c736285955ce75c5b76c6cc5450de0e417570d97c54aaa88538631d8b8909c3',
    ];

    /** get_equity_orders' documented state filter values. Anything else is unknown, never guessed. */
    public const ORDER_STATES = ['new', 'queued', 'confirmed', 'unconfirmed', 'partially_filled', 'filled', 'cancelled', 'rejected', 'failed', 'voided'];

    /**
     * "Idempotency key (UUID) ... the upstream deduplicates by ref_id." One stable key per logical order, re-sent unchanged
     * on transport retries. Orders report it back, which is the only evidence of which agent placed an order: approved
     * orders read placed_agent 'user', and agent_initiated does not identify the agent.
     */
    public const REF_ID_DEDUPLICATES = true;

    /**
     * With customer approval required, place_equity_order "does not place the order: it goes to the customer to approve or
     * decline in the Robinhood app"; the response carries data.approval (id, state, expires_at, is_edited) instead of
     * data.order. The customer can edit before approving, and pending approvals expire on their own. Approval state
     * spellings are not yet observed, so no code branches on them.
     */
    public const APPROVAL_MAY_REPLACE_ORDER = true;

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
