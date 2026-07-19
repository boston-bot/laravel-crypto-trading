<?php

namespace App\Enums;

enum OrderStatus: string
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

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::FILLED,
            self::CANCELLED,
            self::REJECTED,
        ], true);
    }
}
