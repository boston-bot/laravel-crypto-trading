<?php

namespace App\Enums;

enum HoldoutStatus: string
{
    case Locked = 'locked';
    case Authorized = 'authorized';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    case Inconclusive = 'inconclusive';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Passed, self::Failed, self::Inconclusive], true);
    }
}
