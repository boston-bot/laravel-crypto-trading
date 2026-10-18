<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\MandateVersion;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Paper planning only: never writes effective account policy, configuration, queues or orders. */
final class PaperMandates
{
    public const FIELDS = [
        'initial_cash', 'long_term_pct', 'active_pct', 'cash_pct', 'max_position_pct', 'daily_loss_pause_pct',
        'scan_seconds', 'decision_minutes', 'news_minutes', 'monthly_budget', 'ai_guidance',
    ];

    public function validate(array $input): array
    {
        if (array_diff(array_keys($input), self::FIELDS)) {
            throw ValidationException::withMessages(['mandate' => 'Only the listed paper planning fields can be changed.']);
        }
        $decimal = ['nullable', 'string', 'max:32', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D'];
        $rules = array_fill_keys(['initial_cash', 'long_term_pct', 'active_pct', 'cash_pct', 'max_position_pct', 'daily_loss_pause_pct', 'monthly_budget'], $decimal);
        $rules += ['scan_seconds' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'decision_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'], 'news_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'ai_guidance' => ['nullable', 'string', 'max:6000']];
        $data = Validator::make($input, $rules)->validate();
        $data = array_replace(array_fill_keys(self::FIELDS, null), $data);
        foreach ($rules as $field => $_) {
            if ($data[$field] === '' || $data[$field] === null) {
                $data[$field] = null;

                continue;
            }
            if (str_ends_with($field, '_pct') || in_array($field, ['initial_cash', 'monthly_budget'], true)) {
                $value = Decimal::from($data[$field]);
                $max = $field === 'monthly_budget' ? '150' : (str_ends_with($field, '_pct') ? '100' : '999999999999');
                $positive = in_array($field, ['initial_cash', 'monthly_budget', 'max_position_pct', 'daily_loss_pause_pct'], true);
                if ($value->compareTo(Decimal::from($max)) > 0 || ($positive && $value->compareTo(Decimal::from('0')) <= 0)) {
                    throw ValidationException::withMessages([$field => 'Value is outside the supported paper planning range.']);
                }
            } elseif (in_array($field, ['scan_seconds', 'decision_minutes', 'news_minutes'], true)) {
                $data[$field] = (int) $data[$field];
            }
        }
        $allocations = array_map(fn ($f) => $data[$f], ['long_term_pct', 'active_pct', 'cash_pct']);
        if (count(array_filter($allocations, fn ($v) => $v !== null)) !== 0) {
            if (in_array(null, $allocations, true) || Decimal::from($allocations[0])->plus(Decimal::from($allocations[1]))->plus(Decimal::from($allocations[2]))->compareTo(Decimal::from('100')) !== 0) {
                throw ValidationException::withMessages(['long_term_pct' => 'Supply all three allocations totaling exactly 100%, or leave all three blank.']);
            }
        }

        return $data;
    }

    public function append(McpConnection $connection, int $actor, int $baseVersion, string $requestId, array $input, string $reason, ?int $restoreId = null): MandateVersion
    {
        return DB::transaction(function () use ($connection, $actor, $baseVersion, $requestId, $input, $reason, $restoreId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && $current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed. Reload the control center.');
            $query = MandateVersion::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current));
            $restored = $restoreId ? (clone $query)->whereKey($restoreId)->firstOrFail() : null;
            $payload = $this->validate($restored ? $restored->payload : $input);
            $repeat = (clone $query)->where('request_id', $requestId)->first();
            if ($repeat) {
                abort_unless($repeat->payload === $payload && $repeat->reason === $reason && $repeat->restored_from_id === $restoreId, 409, 'This request was already used for another change.');

                return $repeat;
            }
            $latest = (clone $query)->latest('version')->first();
            abort_unless(($latest?->version ?? 0) === $baseVersion, 409, 'A newer draft was saved. Reload and review it before saving.');

            return MandateVersion::create(['mcp_connection_id' => $current->id, 'scope' => AccountScope::key($current),
                'version' => $baseVersion + 1, 'request_id' => $requestId, 'actor_id' => $actor, 'payload' => $payload,
                'reason' => $reason, 'restored_from_id' => $restoreId, 'created_at' => now()]);
        });
    }
}
