<?php

namespace Tests\Integration;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ExecutionFixture as E;
use Tests\Support\IntegrationCleanup;
use Tests\TestCase;

class PaperDashboardReadTest extends TestCase
{
    private ?int $fixture = null;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'pgsql_test') {
            $this->markTestSkipped('Dedicated PostgreSQL fixture required.');
        }
        parent::setUp();
        $this->artisan('migrate', ['--database' => 'pgsql_test', '--force' => true])->assertSuccessful();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
    }

    protected function tearDown(): void
    {
        IntegrationCleanup::connection($this->fixture);
        parent::tearDown();
    }

    public function test_real_request_uses_a_read_only_consistent_transaction_outside_the_fixture_transaction(): void
    {
        [$c, $portfolio] = E::seed();
        $this->fixture = $c->id;
        $this->assertSame(0, DB::transactionLevel());
        $before = $portfolio->getAttributes();
        $this->actingAs(User::findOrFail($c->user_id))->get('/mcp/paper')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, DB::transactionLevel());
        $this->assertSame($before, $portfolio->fresh()->getAttributes());
        Http::assertNothingSent();
    }
}
