<?php

namespace App\Support\Mcp;

use RuntimeException;

final class TestDatabaseGuard
{
    public static function check(string $driver, string $database, ?string $runtimeDatabase): void
    {
        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }
        if ($driver !== 'pgsql' || $database !== 'mcp_trading_test' || $runtimeDatabase === $database) {
            throw new RuntimeException('Refusing test database setup: use an isolated mcp_trading_test database distinct from runtime.');
        }
    }
}
