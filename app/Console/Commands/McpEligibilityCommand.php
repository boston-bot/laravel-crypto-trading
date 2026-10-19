<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\PaperTrading\EligibilityAssessment;
use App\Models\User;
use App\Services\Monitoring\AccountScope;
use App\Services\PaperTrading\RuleEligibility;
use App\Services\PaperTrading\RuleEligibilityPolicies;
use App\Services\PaperTrading\SymbolUniverse;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpEligibilityCommand extends Command
{
    protected $signature = 'mcp:eligibility {--account=} {--symbol=*} {--request=} {--cutoff=}';

    protected $description = 'Record deterministic eligibility reasons from stored data; no broker, model or order calls';

    public function handle(RuleEligibility $eligibility, SymbolUniverse $universe): int
    {
        if (! is_string($this->option('account')) || ! ctype_digit($this->option('account'))) {
            $this->error('Supply a numeric local --account.');

            return self::FAILURE;
        }
        try {
            $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
            $connection = McpConnection::whereKey((int) $this->option('account'))->whereIn('user_id', $operators)->whereNotNull('bound_account_id')->firstOrFail();
            $request = $this->option('request') ?? (string) Str::uuid();
            $prior = EligibilityAssessment::where('mcp_connection_id', $connection->id)->where('scope', AccountScope::key($connection))->where('request_id', $request)->first();
            $cutoff = $this->option('cutoff') ? CarbonImmutable::parse($this->option('cutoff')) : ($prior?->cutoff ?? CarbonImmutable::now());
            abort_if($cutoff->isFuture(), 422);
            abort_unless(RuleEligibilityPolicies::selected($connection, $cutoff), 409);
            $symbols = $this->option('symbol') ?: $universe->reads($connection, $cutoff);
            foreach (array_unique($symbols) as $symbol) {
                $assessment = $eligibility->record($connection, $connection->user_id, $symbol, $request, $cutoff);
                $this->line(json_encode(['assessment_id' => $assessment->id, 'symbol' => $assessment->symbol, 'status' => $assessment->status], JSON_THROW_ON_ERROR));
            }
            $this->line('Eligibility assessments: '.count(array_unique($symbols)));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Eligibility unavailable. Check account, applied policy, cutoff and request identity.');

            return self::FAILURE;
        }
    }
}
