<?php

namespace App\Services\Broker\Agentic;

final class RobinhoodAccountNormalizer
{
    public static function fields(array $row): array
    {
        // Reviewed observed broker metadata: this is a monitoring role, not an execution grant.
        $agentic = ($row['agentic_allowed'] ?? null) === true
            && ($row['management_type'] ?? null) === 'self_directed'
            && ($row['brokerage_account_type'] ?? null) === 'individual'
            && ($row['type'] ?? null) === 'limited_margin'
            && ($row['state'] ?? null) === 'active'
            && ($row['deactivated'] ?? null) === false && ($row['permanently_deactivated'] ?? null) === false;

        $personal = ($row['agentic_allowed'] ?? null) === false
            && ($row['management_type'] ?? null) === 'self_directed'
            && in_array([$row['type'] ?? null, $row['brokerage_account_type'] ?? null], [['margin', 'individual'], ['limited_margin', 'ira_traditional']], true)
            && ($row['state'] ?? null) === 'active'
            && ($row['deactivated'] ?? null) === false && ($row['permanently_deactivated'] ?? null) === false;

        return ['id' => $row['account_number'] ?? null, 'account_kind' => $agentic ? 'agentic' : ($personal ? 'personal' : 'unknown'),
            'nickname' => $row['nickname'] ?? null, 'account_type' => $row['type'] ?? null,
            'brokerage_account_type' => $row['brokerage_account_type'] ?? null,
            'management_type' => $row['management_type'] ?? null,
            'agentic_allowed' => $row['agentic_allowed'] ?? null, 'status' => $row['state'] ?? null,
            'deactivated' => $row['deactivated'] ?? null, 'permanently_deactivated' => $row['permanently_deactivated'] ?? null];
    }
}
