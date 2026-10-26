<?php

namespace App\Services\Execution;

use App\Models\Execution\OrderIntent;

/** Local-only submission checks. Fail closed until M6 live policy, risk and approval checks are implemented. */
class SubmissionChecks
{
    public function reason(OrderIntent $intent): ?string
    {
        return 'live_submission_checks_unimplemented';
    }
}
