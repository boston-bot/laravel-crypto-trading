<?php

namespace Tests\Unit\Mcp;

use App\Services\Broker\Agentic\BrokerCursor;
use App\Services\Broker\Agentic\ReadError;
use App\Services\Broker\Agentic\ReadFailure;
use PHPUnit\Framework\TestCase;

class BrokerCursorTest extends TestCase
{
    public function test_opaque_and_broker_link_cursors_are_passed_only_as_cursor_data(): void
    {
        $this->assertSame('opaque:cursor', BrokerCursor::normalize('opaque:cursor', 'synthetic-account'));
        $this->assertSame('next/cursor', BrokerCursor::normalize('https://api.robinhood.com/positions/?account_number=synthetic-account&cursor=next%2Fcursor', 'synthetic-account'));
    }

    public function test_foreign_links_and_account_changes_are_refused(): void
    {
        foreach (['https://untrusted.example/path?cursor=x' => ReadError::Incomplete,
            'https://api.robinhood.com/positions/?account_number=other&cursor=x' => ReadError::AccountMismatch,
            'https://api.robinhood.com/positions/' => ReadError::Incomplete] as $url => $reason) {
            try {
                BrokerCursor::normalize($url, 'synthetic-account');
                $this->fail();
            } catch (ReadFailure $failure) {
                $this->assertSame($reason, $failure->reason);
            }
        }
    }
}
