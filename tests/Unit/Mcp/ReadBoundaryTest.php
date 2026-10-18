<?php

namespace Tests\Unit\Mcp;

use App\Services\Broker\Agentic\Operation;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use App\Services\Broker\Agentic\RpcHttpClient;
use App\Services\Broker\Agentic\TrustedHttp;
use GuzzleHttp\Psr7\Request;
use PHPUnit\Framework\TestCase;

class ReadBoundaryTest extends TestCase
{
    public function test_rpc_boundary_blocks_mutations_and_foreign_urls_before_network(): void
    {
        $client = new RpcHttpClient(new TrustedHttp, 'synthetic-access', ['synthetic_read_positions']);
        foreach ([['tools/call', ['name' => 'submit_order']], ['sampling/createMessage', []], ['tools/call', ['name' => 'review_order']],
            ...array_map(fn ($name) => ['tools/call', ['name' => $name]], ['create_watchlist', 'update_watchlist', 'add_to_watchlist', 'remove_from_watchlist', 'follow_watchlist', 'unfollow_watchlist'])] as [$method, $params]) {
            try {
                $client->sendRequest(new Request('POST', TrustedHttp::RESOURCE, [], json_encode(['method' => $method, 'params' => $params])));
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::Forbidden, $failure->reason);
            }
        }
        try {
            $client->sendRequest(new Request('POST', 'https://other.example', [], '{}'));
            $this->fail();
        } catch (ReadFailure $failure) {
            $this->assertSame(ReadError::Forbidden, $failure->reason);
        }
        $this->assertNull(Operation::tryFrom('submit_order'));
        $this->assertNull(Operation::tryFrom('watchlist_update'));
    }

    public function test_trusted_oauth_url_boundary_rejects_credentials_redirects_and_wrong_hosts(): void
    {
        foreach (['http://agent.robinhood.com', 'https://agent.robinhood.com.evil.example', 'https://user@agent.robinhood.com', 'https://agent.robinhood.com:443', 'https://api.robinhood.com/oauth#fragment'] as $url) {
            try {
                TrustedHttp::validateUrl($url);
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame(ReadError::OAuth, $failure->reason);
            }
        }
    }
}
