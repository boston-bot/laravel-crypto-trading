<?php

namespace App\Policies;

use App\Models\User;

class TradingPolicy
{
    public function approve(User $user): bool
    {
        return $this->isAllowedApprover($user);
    }

    public function override(User $user): bool
    {
        return $this->isAllowedApprover($user);
    }

    private function isAllowedApprover(User $user): bool
    {
        $emails = array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            explode(',', (string) env('TRADING_APPROVER_EMAILS', ''))
        )));

        return in_array(strtolower((string) $user->email), $emails, true);
    }
}
