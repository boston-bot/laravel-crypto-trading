<?php

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PhoneSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_secret_requires_operator_and_explicit_csrf_protected_reveal(): void
    {
        config(['operations.ntfy.url' => 'https://ntfy.example.test', 'operations.ntfy.topic' => 'synthetic-private-topic-123456', 'operations.ntfy.token' => 'synthetic-server-token']);
        $this->postJson('/mcp/operations/phone-setup')->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/mcp/operations/phone-setup')->assertForbidden();
        config(['mcp.operator_emails' => [$user->email]]);
        $this->get('/mcp/dashboard/health')->assertOk()->assertDontSee('synthetic-private-topic-123456')->assertSee('Show phone subscription setup');
        $this->post('/mcp/operations/phone-setup')->assertOk()->assertSee('synthetic-private-topic-123456')->assertDontSee('synthetic-server-token')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        Http::assertNothingSent();
        app()->instance('env', 'local');
        $this->post('/mcp/operations/phone-setup')->assertStatus(419);
    }

    public function test_missing_topic_is_not_fabricated_and_no_message_is_sent(): void
    {
        $user = User::factory()->create();
        config(['mcp.operator_emails' => [$user->email], 'operations.ntfy.topic' => null]);
        $this->actingAs($user)->post('/mcp/operations/phone-setup')->assertStatus(409);
        Http::assertNothingSent();
    }
}
