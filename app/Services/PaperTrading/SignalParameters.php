<?php

namespace App\Services\PaperTrading;

final class SignalParameters
{
    public const VERSION = 'swing_signal_parameters_v1';

    public static function current(): array
    {
        $values = StrategyParameters::DEFAULTS + ['entry_delay_minutes' => 15, 'time_exit_sessions' => 20, 'cooldown_sessions' => 5];

        return ['version' => self::VERSION, 'values' => $values, 'hash' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
    }
}
