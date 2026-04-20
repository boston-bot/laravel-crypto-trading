<?php

namespace App\Enums;

enum BrokerType: string
{
    case COINBASE = 'coinbase';
    case ROBINHOOD = 'robinhood';

    public static function default(): self
    {
        $configured = (string) config('broker.default', self::COINBASE->value);

        return self::tryFrom($configured) ?? self::COINBASE;
    }
}
