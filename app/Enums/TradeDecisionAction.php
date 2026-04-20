<?php

namespace App\Enums;

enum TradeDecisionAction: string
{
    case BUY = 'buy';
    case SELL = 'sell';
    case HOLD = 'hold';
}

