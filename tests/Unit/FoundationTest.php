<?php

namespace Tests\Unit;

use App\Logging\SafeLogging;
use App\Support\Mcp\Decimal;
use App\Support\Mcp\TestDatabaseGuard;
use Brick\Math\RoundingMode;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class FoundationTest extends TestCase
{
    public function test_decimals_keep_precision_and_require_explicit_rounding(): void
    {
        $value = Decimal::from('9007199254740993.10')->plus(Decimal::from('0.20'));
        $this->assertSame('"9007199254740993.30"', json_encode($value));
        $this->assertSame('0.02', (string) Decimal::from('0.1')->multipliedBy(Decimal::from('0.2')));
        $this->assertSame('1.01', (string) Decimal::from('1.005')->rounded(2, RoundingMode::HALF_UP));
        $this->assertSame(-1, Decimal::from('0.99')->compareTo(Decimal::from('1.00')));
    }

    public function test_boundary_rejects_unquoted_and_non_decimal_values(): void
    {
        foreach ([0.1, 1, null, '1e3', 'NaN', '1,000', '', ' 1.0'] as $value) {
            try {
                Decimal::from($value);
                $this->fail('Invalid decimal accepted');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_quantity_division_can_round_down_before_order_sizing_without_changing_default_rounding(): void
    {
        $value = Decimal::from('0.999999999999999999');
        $this->assertSame('0', (string) $value->dividedBy(Decimal::from('1'), 0, RoundingMode::DOWN));
        $this->assertSame('1', (string) $value->dividedBy(Decimal::from('1'), 0));
    }

    public function test_test_database_guard_refuses_runtime_or_undesignated_targets(): void
    {
        TestDatabaseGuard::check('sqlite', ':memory:', null);
        TestDatabaseGuard::check('pgsql', 'mcp_trading_test', 'postgres');
        foreach ([['pgsql', 'postgres', 'postgres'], ['pgsql', 'mcp_trading_test', 'mcp_trading_test'], ['pgsql', 'other_test', 'postgres'], ['sqlite', '/tmp/runtime.sqlite', null]] as $args) {
            try {
                TestDatabaseGuard::check(...$args);
                $this->fail('Unsafe test target accepted');
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_structured_logs_omit_secrets_payloads_and_exceptions(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new Logger('test');
        $logger->pushHandler(new StreamHandler($stream));
        (new SafeLogging)($logger);
        $logger->error('Bearer super-secret', ['event' => 'sync.failed', 'password' => 'secret', 'account_id' => 'private', 'exception' => new \RuntimeException('sensitive'), 'body' => ['access_token' => 'token']]);
        rewind($stream);
        $record = json_decode(stream_get_contents($stream), true);
        $this->assertSame('application_event', $record['message']);
        $this->assertSame(['event' => 'sync.failed'], $record['context']);
    }
}
