<?php

namespace App\Services\Research;

final class ResearchReadiness
{
    /** Null when research may be attempted; otherwise the typed reason it is unavailable. Monitoring is unaffected. */
    public static function reason(): ?ResearchError
    {
        if (! config('research.enabled')) {
            return ResearchError::Disabled;
        }
        if (config('research.provider') !== 'openai') {
            return ResearchError::ProviderUnsupported;
        }
        if (! is_string(config('research.model')) || ! preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', config('research.model'))) {
            return ResearchError::ModelUnconfigured;
        }
        if (! is_string(config('research.api_key')) || config('research.api_key') === '') {
            return ResearchError::CredentialMissing;
        }
        try {
            ResearchBudget::config();
        } catch (ResearchFailure $failure) {
            return $failure->reason;
        }

        return null;
    }
}
