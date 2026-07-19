<?php

namespace App\Enums;

enum EvaluationResolution: string
{
    case ACTIONABLE = 'actionable';
    case HOLD = 'hold';
    case BLOCKED_BY_EVIDENCE = 'blocked_by_evidence';
    case BLOCKED_BY_STRATEGY = 'blocked_by_strategy';
    case BLOCKED_BY_PORTFOLIO_RISK = 'blocked_by_portfolio_risk';
    case BLOCKED_BY_POLICY = 'blocked_by_policy';

    public function permitsDecision(): bool
    {
        return $this === self::ACTIONABLE;
    }
}
