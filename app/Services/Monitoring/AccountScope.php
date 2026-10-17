<?php

namespace App\Services\Monitoring;

use App\Models\McpConnection;
use InvalidArgumentException;

final class AccountScope
{
    public static function key(McpConnection $connection): string
    {
        if (! $connection->bound_account_id) {
            throw new InvalidArgumentException('An account binding is required.');
        }

        return hash_hmac('sha256', $connection->bound_account_id, (string) config('app.key'));
    }
}
