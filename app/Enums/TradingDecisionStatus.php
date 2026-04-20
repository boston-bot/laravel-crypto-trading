<?php

namespace App\Enums;

enum TradingDecisionStatus: string
{
    case DRAFT = 'draft';
    case BLOCKED_BY_POLICY = 'blocked_by_policy';
    case AWAITING_HUMAN_APPROVAL = 'awaiting_human_approval';
    case APPROVED = 'approved';
    case SUBMITTED = 'submitted';
    case PARTIALLY_FILLED = 'partially_filled';
    case FILLED = 'filled';
    case CANCELLED = 'cancelled';
    case REJECTED = 'rejected';
    case RECONCILIATION_REQUIRED = 'reconciliation_required';

    public function isExecutable(): bool
    {
        return in_array($this, [
            self::APPROVED,
            self::SUBMITTED,
            self::PARTIALLY_FILLED,
        ], true);
    }
}

