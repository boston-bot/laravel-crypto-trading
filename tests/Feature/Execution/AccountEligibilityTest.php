<?php

namespace Tests\Feature\Execution;

use App\Models\Monitoring\Snapshot;
use App\Services\Execution\AccountEligibility;
use App\Services\Execution\SubmissionChecks;
use App\Services\Monitoring\AccountScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LiveBrokerGateway;
use Tests\Support\LiveExecutionFixture as F;
use Tests\TestCase;

class AccountEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_failed_partial_revoked_and_disabled_account_reads_deny_without_failing_monitoring(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        $broker = new LiveBrokerGateway;
        [$c, $signal] = F::seed($broker);
        $checks = app(SubmissionChecks::class);
        $this->assertNull($checks->plan($c, $signal)['reason']);
        foreach ([['agentic_allowed' => false], ['deactivated' => true], ['status' => 'inactive'], ['id' => 'other-account'], null] as $patch) {
            $broker->accountPatch = $patch;
            F::sync($c, $broker);
            $snapshot = Snapshot::where('mcp_connection_id', $c->id)->latest('publication_version')->firstOrFail();
            $this->assertSame('complete', $snapshot->status);
            $this->assertNotNull(app(AccountEligibility::class)->reason($c, $snapshot));
            $this->assertNotNull($checks->plan($c, $signal)['reason']);
        }
        $broker->accountPatch = [];
        $broker->partialAccounts = true;
        F::sync($c, $broker);
        $this->assertSame('account_trading_eligibility_unverified', $checks->plan($c, $signal)['reason']);
        config(['robinhood_mcp.account_eligibility_enabled' => false]);
        F::sync($c, $broker);
        $this->assertSame('account_trading_eligibility_unverified', $checks->plan($c, $signal)['reason']);
    }

    public function test_metadata_must_match_the_account_contract_authorization_and_fresh_retrieval(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        [$c] = F::seed(new LiveBrokerGateway);
        $snapshot = Snapshot::where('mcp_connection_id', $c->id)->where('scope', AccountScope::key($c))->latest('publication_version')->firstOrFail();
        $service = app(AccountEligibility::class);
        $this->assertNull($service->reason($c, $snapshot));
        foreach ([['account_eligibility.auth_generation', $c->auth_generation + 1], ['account_eligibility.contract_fingerprint', 'changed'],
            ['account_eligibility.read.source', 'untrusted'], ['account_eligibility.read.retrieved_at', now()->addSecond()->toISOString()],
            ['account_eligibility.read.retrieved_at', now()->subMinutes(5)->toISOString()], ['account_eligibility.read.records.0.data.agentic_allowed', 'true']] as [$path, $value]) {
            $copy = clone $snapshot;
            $payload = $copy->payload;
            data_set($payload, $path, $value);
            $copy->payload = $payload;
            $this->assertNotNull($service->reason($c, $copy));
        }
        $copy = clone $snapshot;
        $payload = $copy->payload;
        $payload['account_eligibility']['read']['records'][] = $payload['account_eligibility']['read']['records'][0];
        $copy->payload = $payload;
        $this->assertSame('account_trading_eligibility_unverified', $service->reason($c, $copy));
    }
}
