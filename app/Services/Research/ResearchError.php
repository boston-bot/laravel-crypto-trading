<?php

namespace App\Services\Research;

enum ResearchError: string
{
    case Disabled = 'research_disabled';
    case ProviderUnsupported = 'provider_unsupported';
    case ModelUnconfigured = 'model_unconfigured';
    case CredentialMissing = 'credential_missing';
    case CostUnreviewed = 'provider_cost_unreviewed';
    case BudgetConfigInvalid = 'budget_config_invalid';
    case BudgetExhausted = 'budget_exhausted';
    case BudgetReduced = 'budget_reduced';
    case Authentication = 'model_authentication_failed';
    case RequestRejected = 'model_request_rejected';
    case RateLimited = 'model_rate_limited';
    case Timeout = 'model_timeout';
    case Transport = 'model_unavailable';
    case Refused = 'model_refused';
    case Incomplete = 'model_incomplete';
    case MalformedOutput = 'malformed_output';
    case InvalidTimestamp = 'missing_or_invalid_timestamp';
    case UnknownSource = 'unknown_source_reference';
    case FactAfterSource = 'fact_time_after_source';
    case UnsupportedSymbol = 'unsupported_symbol';
    case UnsupportedClaim = 'claim_without_evidence';
    case ExecutionInstruction = 'execution_instruction';
    case InvalidInvalidation = 'invalid_invalidation';
    case InvalidReviewTime = 'invalid_review_time';
    case ScopeChanged = 'scope_changed';
    case LeaseLost = 'lease_lost';

    /** Only output-format failures get the single bounded repair attempt; semantic rejections fail visibly. */
    public function repairable(): bool
    {
        return in_array($this, [self::MalformedOutput, self::InvalidTimestamp], true);
    }
}
