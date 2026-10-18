<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\ExecutionVersion;
use App\Models\PaperTrading\PolicyApplication;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Drafts and explicit applications are append-only. Neither enables orders or market/AI calls. */
final class ExecutionPolicies
{
    public const FIELDS = ['decision_sleeve', 'order_type', 'session', 'time_in_force', 'initial_holdings', 'allowed_symbols',
        'allow_entries', 'allow_exits', 'allow_ai_sizing', 'allow_fractional', 'allow_fractional_limit',
        'fee_per_share', 'fee_bps', 'slippage_bps', 'limit_offset_bps', 'quote_max_age_seconds'];

    public static function current(Portfolio $portfolio): ?ExecutionVersion
    {
        $version = ExecutionVersion::where('paper_portfolio_id', $portfolio->id)->where('kind', 'applied')->latest('version')->first();

        return $version && $version->policy_application_id === PolicyApplication::where('paper_portfolio_id', $portfolio->id)
            ->where('revision', $portfolio->policy_revision)->value('id') ? $version : null;
    }

    public function validate(array $input, bool $complete = false): array
    {
        if (array_diff(array_keys($input), self::FIELDS)) {
            throw ValidationException::withMessages(['execution_json' => 'Only paper execution settings may be supplied.']);
        }
        $decimal = ['nullable', 'string', 'regex:/^\d{1,8}(?:\.\d{1,8})?$/D'];
        $rules = ['decision_sleeve' => ['nullable', 'in:active,long_term'], 'order_type' => ['nullable', 'in:limit'],
            'session' => ['nullable', 'in:regular'], 'time_in_force' => ['nullable', 'in:day'],
            'initial_holdings' => ['nullable', 'in:cash_only'], 'allowed_symbols' => ['nullable', 'array', 'list', 'min:1', 'max:100'],
            'allowed_symbols.*' => ['required', 'string', 'distinct:strict', 'regex:/^[A-Z][A-Z0-9.-]{0,15}$/D'],
            'fee_per_share' => $decimal, 'fee_bps' => $decimal, 'slippage_bps' => $decimal, 'limit_offset_bps' => $decimal,
            'quote_max_age_seconds' => ['nullable', 'integer', 'min:1', 'max:300']];
        foreach (['allow_entries', 'allow_exits', 'allow_ai_sizing', 'allow_fractional', 'allow_fractional_limit'] as $field) {
            if (isset($input[$field]) && ! is_bool($input[$field])) {
                throw ValidationException::withMessages([$field => 'Use a JSON boolean, not text or a number.']);
            }
            $rules[$field] = ['nullable', 'boolean'];
        }
        $payload = array_replace(array_fill_keys(self::FIELDS, null), Validator::make($input, $rules)->validate());
        // Older drafts/clients contained only per-share fees. Preserve that fee contract.
        if (! array_key_exists('fee_bps', $input)) {
            $payload['fee_bps'] = '0';
        }
        if ($payload['fee_bps'] !== null && Decimal::from($payload['fee_bps'])->compareTo(Decimal::from('10000')) > 0) {
            throw ValidationException::withMessages(['fee_bps' => 'Percentage fees cannot exceed 10000 basis points.']);
        }
        foreach (['slippage_bps', 'limit_offset_bps'] as $field) {
            if ($payload[$field] !== null && Decimal::from($payload[$field])->compareTo(Decimal::from('9999')) > 0) {
                throw ValidationException::withMessages([$field => 'Value must be below 10000 basis points.']);
            }
        }
        if ($payload['quote_max_age_seconds'] !== null) {
            $payload['quote_max_age_seconds'] = (int) $payload['quote_max_age_seconds'];
        }
        if ($payload['allowed_symbols'] !== null) {
            sort($payload['allowed_symbols']);
        }
        if ($complete && in_array(null, $payload, true)) {
            throw ValidationException::withMessages(['execution_json' => 'Choose every execution setting explicitly before applying.']);
        }
        if ($payload['allow_fractional_limit'] === true && $payload['allow_fractional'] !== true) {
            throw ValidationException::withMessages(['allow_fractional_limit' => 'Fractional limits require fractional shares to be allowed.']);
        }

        return $payload;
    }

    public function save(McpConnection $connection, int $actor, int $base, string $requestId, array $input, string $reason): ExecutionVersion
    {
        $payload = $this->validate($input);

        return $this->append($connection, $actor, $base, $requestId, $payload, $reason);
    }

    public function apply(McpConnection $connection, int $actor, int $base, string $requestId, int $draftId): ExecutionVersion
    {
        return $this->append($connection, $actor, $base, $requestId, [], 'Explicitly applied paper execution rules.', $draftId);
    }

    private function append(McpConnection $connection, int $actor, int $base, string $requestId, array $payload, string $reason, ?int $draftId = null): ExecutionVersion
    {
        Validator::make(['request_id' => $requestId, 'base' => $base, 'reason' => $reason], ['request_id' => ['required', 'uuid'],
            'base' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500']])->validate();

        return DB::transaction(function () use ($connection, $actor, $base, $requestId, $payload, $reason, $draftId) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->bound_account_id && AccountScope::key($current) === AccountScope::key($connection), 409, 'Account binding changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $query = ExecutionVersion::where('paper_portfolio_id', $portfolio->id);
            $policyId = PolicyApplication::where('paper_portfolio_id', $portfolio->id)->where('revision', $portfolio->policy_revision)->value('id');
            if ($draftId) {
                $draft = (clone $query)->whereKey($draftId)->where('kind', 'draft')->firstOrFail();
                abort_unless($draft->policy_application_id === $policyId, 409, 'The allocation policy changed; save and review a new execution draft.');
                $payload = $this->validate($draft->payload, true);
            }
            $repeat = (clone $query)->where('request_id', $requestId)->first();
            if ($repeat) {
                abort_unless($repeat->payload === $payload && $repeat->draft_id === $draftId && $repeat->reason === $reason, 409, 'Request already used for different execution settings.');

                return $repeat;
            }
            $latest = (clone $query)->latest('version')->first();
            abort_unless(($latest?->version ?? 0) === $base && (! $draftId || $latest->id === $draftId), 409, 'Execution settings changed. Reload before saving or applying.');

            return ExecutionVersion::create(['paper_portfolio_id' => $portfolio->id, 'policy_application_id' => $policyId,
                'actor_id' => $actor, 'version' => $base + 1, 'request_id' => $requestId, 'kind' => $draftId ? 'applied' : 'draft',
                'draft_id' => $draftId, 'payload' => $payload, 'reason' => $reason, 'created_at' => now()]);
        });
    }
}
