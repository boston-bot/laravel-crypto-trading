<?php

namespace App\Services\PaperTrading;

use App\Models\McpConnection;
use App\Models\PaperTrading\EligibilityPolicy;
use App\Models\PaperTrading\Portfolio;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit owner selection of a rule universe. It never inherits symbols or parameters from model text. */
final class RuleEligibilityPolicies
{
    public const DEFAULTS = ['minimum_price' => '5', 'minimum_dollar_volume' => '10000000', 'maximum_spread_pct' => '0.5'];

    public static function current(Portfolio $portfolio, ?CarbonImmutable $cutoff = null): ?EligibilityPolicy
    {
        return EligibilityPolicy::where('paper_portfolio_id', $portfolio->id)
            ->when($cutoff, fn ($q) => $q->where('applied_at', '<=', $cutoff->utc()->format('Y-m-d H:i:s.u')))->latest('version')->first();
    }

    public static function selected(McpConnection $connection, ?CarbonImmutable $cutoff = null): ?EligibilityPolicy
    {
        $portfolio = Portfolio::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->first();

        return $portfolio ? self::current($portfolio, $cutoff) : null;
    }

    public function validate(array $parameters): array
    {
        if (array_diff(array_keys($parameters), array_keys(self::DEFAULTS))) {
            throw ValidationException::withMessages(['parameters' => 'Only the three eligibility thresholds may be supplied.']);
        }
        $rules = array_fill_keys(array_keys(self::DEFAULTS), ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/D']);
        $values = Validator::make($parameters, $rules)->validate();
        foreach (['minimum_price' => ['1', '1000'], 'minimum_dollar_volume' => ['1000000', '100000000000'], 'maximum_spread_pct' => ['0.01', '1']] as $field => [$min, $max]) {
            $value = Decimal::from($values[$field]);
            if ($value->compareTo(Decimal::from($min)) < 0 || $value->compareTo(Decimal::from($max)) > 0) {
                throw ValidationException::withMessages([$field => "Use a decimal string between {$min} and {$max}."]);
            }
        }

        return array_replace(self::DEFAULTS, $values);
    }

    public function apply(McpConnection $connection, int $actor, int $base, string $request, array $parameters): EligibilityPolicy
    {
        $values = $this->validate($parameters);
        Validator::make(['request' => $request, 'base' => $base], ['request' => ['required', 'uuid'], 'base' => ['integer', 'min:0']])->validate();

        return DB::transaction(function () use ($connection, $actor, $base, $request, $values) {
            $current = McpConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->user_id === $actor && User::whereKey($actor)->whereIn('email', config('mcp.operator_emails'))->exists(), 403);
            abort_unless($current->status === 'connected' && $current->account_role === 'agentic_monitoring' && $current->bound_account_id &&
                $connection->bound_account_id && AccountScope::key($current) === AccountScope::key($connection) && $current->auth_generation === $connection->auth_generation, 409, 'Account authorization changed.');
            $portfolio = Portfolio::where('mcp_connection_id', $current->id)->where('scope', AccountScope::key($current))->lockForUpdate()->firstOrFail();
            $execution = ExecutionPolicies::current($portfolio);
            abort_unless($execution, 409, 'Applied execution settings are required.');
            $payload = ['universe' => 'watchlist_rules', 'rule_version' => EligibilityRules::VERSION, 'parameters' => $values, 'history_sessions' => 252, 'volume_sessions' => 20];
            if ($repeat = EligibilityPolicy::where('paper_portfolio_id', $portfolio->id)->where('request_id', $request)->first()) {
                abort_unless($repeat->payload === $payload && $repeat->actor_id === $actor, 409, 'Request already used for different eligibility rules.');

                return $repeat;
            }
            abort_unless((self::current($portfolio)?->version ?? 0) === $base, 409, 'Eligibility policy changed. Reload before applying.');

            return EligibilityPolicy::create(['paper_portfolio_id' => $portfolio->id, 'execution_version_id' => $execution->id, 'actor_id' => $actor,
                'version' => $base + 1, 'request_id' => $request, 'payload' => $payload, 'applied_at' => now()]);
        });
    }
}
