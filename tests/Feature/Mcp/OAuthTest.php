<?php

namespace Tests\Feature\Mcp;

use App\Models\McpConnection;
use App\Models\McpOauthAttempt;
use App\Models\McpOauthCredential;
use App\Models\User;
use App\Services\Broker\Agentic\OAuthService;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\TrustedHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['robinhood_mcp.enabled' => true, 'robinhood_mcp.redirect_uri' => 'http://127.0.0.1:8080/mcp/robinhood/callback']);
    }

    private function start(User $user, string $session = 'synthetic-session'): array
    {
        $url = app(OAuthService::class)->begin($user->id, $session);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    public function test_pkce_callback_encrypted_storage_and_restart(): void
    {
        McpFixture::oauthFake();
        $user = User::factory()->create();
        $query = $this->start($user);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame(TrustedHttp::RESOURCE, $query['resource']);
        $attempt = McpOauthAttempt::firstOrFail();
        $this->assertSame(rtrim(strtr(base64_encode(hash('sha256', $attempt->verifier, true)), '+/', '-_'), '='), $query['code_challenge']);
        $this->assertStringNotContainsString($attempt->verifier, DB::table('mcp_oauth_attempts')->value('verifier'));
        app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state'], 'code' => 'synthetic-code', 'iss' => TrustedHttp::RESOURCE]);
        $connection = McpConnection::firstOrFail();
        $this->assertSame('connected', $connection->status);
        $this->assertNull($connection->bound_account_id);
        $this->assertStringNotContainsString('synthetic-access', DB::table('mcp_oauth_credentials')->value('access_token'));
        $this->assertStringNotContainsString('synthetic-refresh', McpOauthCredential::firstOrFail()->toJson());
        $this->app->forgetInstance(OAuthService::class);
        Http::preventStrayRequests();
        $this->assertSame('synthetic-access', app(OAuthService::class)->accessToken($connection->fresh()));
    }

    public function test_state_is_session_bound_expiring_and_single_use(): void
    {
        McpFixture::oauthFake();
        $user = User::factory()->create();
        $query = $this->start($user);
        foreach ([['session' => 'other-session', 'user' => $user->id], ['session' => 'synthetic-session', 'user' => User::factory()->create()->id]] as $case) {
            try {
                app(OAuthService::class)->complete($case['user'], $case['session'], ['state' => $query['state']]);
                $this->fail('Mismatched OAuth state accepted');
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::OAuth, $failure->reason);
            }
        }
        McpOauthAttempt::first()->update(['expires_at' => now()->subSecond()]);
        try {
            app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state']]);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::OAuth, $failure->reason);
        }
        $query = $this->start($user);
        app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state'], 'code' => 'synthetic-code', 'iss' => TrustedHttp::RESOURCE]);
        try {
            app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state'], 'code' => 'synthetic-code', 'iss' => TrustedHttp::RESOURCE]);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::OAuth, $failure->reason);
        }
        Http::assertSentCount(7); // two discoveries + registrations, one exchange
    }

    public function test_wrong_issuer_is_consumed_without_token_exchange(): void
    {
        McpFixture::oauthFake();
        $user = User::factory()->create();
        $query = $this->start($user);
        try {
            app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state'], 'code' => 'synthetic-code', 'iss' => 'https://wrong.example']);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::OAuth, $failure->reason);
        }
        $this->assertNotNull(McpOauthAttempt::first()->consumed_at);
        $this->assertSame('reconnect_required', McpConnection::first()->status);
        Http::assertSentCount(3);
    }

    public function test_refresh_rotation_is_persisted_and_second_reader_reuses_it(): void
    {
        $connection = McpFixture::connection();
        McpOauthCredential::first()->update(['expires_at' => now()->subMinute()]);
        McpFixture::oauthFake(['access_token' => 'synthetic-rotated-access', 'refresh_token' => 'synthetic-rotated-refresh', 'token_type' => 'bearer', 'expires_in' => 3600]);
        $this->assertSame('synthetic-rotated-access', app(OAuthService::class)->accessToken($connection));
        $this->assertSame('synthetic-rotated-access', app(OAuthService::class)->accessToken($connection->fresh()));
        $this->assertSame('synthetic-rotated-refresh', McpOauthCredential::first()->refresh_token);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['resource'] === TrustedHttp::RESOURCE && $request['grant_type'] === 'refresh_token');
    }

    public function test_refresh_request_holds_no_database_transaction_or_row_lock(): void
    {
        $connection = McpFixture::connection();
        McpOauthCredential::first()->update(['expires_at' => now()->subMinute()]);
        $baseline = DB::transactionLevel();
        $levels = [];
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake(['https://api.robinhood.com/oauth2/token/' => function () use (&$levels) {
            $levels[] = DB::transactionLevel();

            return Http::response(McpFixture::tokens());
        }]);
        $this->assertSame('synthetic-access', app(OAuthService::class)->accessToken($connection));
        $this->assertSame([$baseline], $levels);
        $this->assertTrue(McpOauthCredential::first()->expires_at->isFuture());
    }

    public function test_reconnect_during_a_refresh_is_never_overwritten_or_wiped(): void
    {
        foreach ([200, 400] as $status) {
            $connection = McpFixture::connection();
            McpOauthCredential::where('mcp_connection_id', $connection->id)->update(['expires_at' => now()->subMinute()]);
            Http::swap((new Factory)->preventStrayRequests());
            Http::fake(['https://api.robinhood.com/oauth2/token/' => function () use ($connection, $status) {
                // The owner reconnects while this refresh is in flight.
                McpOauthCredential::where('mcp_connection_id', $connection->id)->firstOrFail()->update(['access_token' => 'synthetic-reconnected-access',
                    'refresh_token' => 'synthetic-reconnected-refresh', 'expires_at' => now()->addHour()]);
                $connection->increment('auth_generation');

                return $status === 200 ? Http::response(['access_token' => 'synthetic-stale-access', 'refresh_token' => 'synthetic-stale-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600])
                    : Http::response(['error' => 'invalid_grant'], 400);
            }]);
            $this->assertSame('synthetic-reconnected-access', app(OAuthService::class)->accessToken($connection));
            $credential = McpOauthCredential::where('mcp_connection_id', $connection->id)->firstOrFail();
            $this->assertSame('synthetic-reconnected-refresh', $credential->refresh_token, (string) $status);
            $this->assertSame('connected', $connection->fresh()->status);
        }
    }

    public function test_explicit_renewal_rotates_credentials_without_expiring_or_rebinding_account(): void
    {
        $connection = McpFixture::connection();
        McpFixture::oauthFake(['access_token' => 'synthetic-renewed-access', 'refresh_token' => 'synthetic-renewed-refresh', 'token_type' => 'Bearer', 'expires_in' => 3600]);
        $this->assertSame('synthetic-renewed-access', app(OAuthService::class)->renew($connection));
        $this->assertSame('synthetic-agentic', $connection->fresh()->bound_account_id);
        $this->assertSame('synthetic-renewed-refresh', McpOauthCredential::first()->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_revoked_refresh_requires_reconnect_and_cannot_loop(): void
    {
        $connection = McpFixture::connection();
        McpOauthCredential::first()->update(['expires_at' => now()->subMinute()]);
        McpFixture::oauthFake(['error' => 'invalid_grant'], 400);
        for ($i = 0; $i < 2; $i++) {
            try {
                app(OAuthService::class)->accessToken($connection);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Authentication, $failure->reason);
            }
        }
        $this->assertSame('reconnect_required', $connection->fresh()->status);
        $this->assertNull(McpOauthCredential::first()->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_ambiguous_refresh_timeout_requires_reconnect_without_retry(): void
    {
        $connection = McpFixture::connection();
        McpOauthCredential::first()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['https://api.robinhood.com/oauth2/token/' => Http::failedConnection()]);
        for ($i = 0; $i < 2; $i++) {
            try {
                app(OAuthService::class)->accessToken($connection);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame($i === 0 ? ReadError::Timeout : ReadError::Authentication, $failure->reason);
            }
        }
        $this->assertSame('reconnect_required', $connection->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_refresh_rate_limit_preserves_credentials_for_later_retry(): void
    {
        $connection = McpFixture::connection();
        McpOauthCredential::first()->update(['expires_at' => now()->subMinute()]);
        McpFixture::oauthFake(['error' => 'slow_down'], 429);
        try {
            app(OAuthService::class)->accessToken($connection);
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::RateLimited, $failure->reason);
        }
        $this->assertSame('connected', $connection->fresh()->status);
        $this->assertSame('synthetic-refresh', McpOauthCredential::first()->refresh_token);
        Http::assertSentCount(1);
    }

    public function test_reauthorization_invalidates_old_binding(): void
    {
        $connection = McpFixture::connection();
        $user = User::findOrFail($connection->user_id);
        McpFixture::oauthFake();
        $query = $this->start($user);
        app(OAuthService::class)->complete($user->id, 'synthetic-session', ['state' => $query['state'], 'code' => 'synthetic-code', 'iss' => TrustedHttp::RESOURCE]);
        $this->assertNull($connection->fresh()->bound_account_id);
        $this->assertSame(1, $connection->fresh()->auth_generation);
    }

    public function test_untrusted_discovery_cannot_redirect_tokens_or_change_resource(): void
    {
        Http::fake(['https://agent.robinhood.com/.well-known/oauth-protected-resource/mcp/trading' => Http::response(['resource' => 'https://wrong.example', 'authorization_servers' => ['https://wrong.example']])]);
        $this->expectException(ReadFailure::class);
        app(OAuthService::class)->discover();
    }
}
