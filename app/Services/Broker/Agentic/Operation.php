<?php

namespace App\Services\Broker\Agentic;

// No order review, submit, cancel, watchlist mutation or configuration operation exists here.
enum Operation: string
{
    case Accounts = 'accounts';
    case Portfolio = 'portfolio';
    case Positions = 'positions';
    case Orders = 'orders';
    case Instruments = 'instruments';
    case Quotes = 'quotes';
    case Classification = 'classification';
    case Dividends = 'dividends';
    case Tradability = 'tradability';
    case PriceBook = 'price_book';
    case Watchlists = 'watchlists';
    case WatchlistItems = 'watchlist_items';
    case CashFlows = 'cash_flows';
    case History = 'history';
    case Calendar = 'calendar';
}
