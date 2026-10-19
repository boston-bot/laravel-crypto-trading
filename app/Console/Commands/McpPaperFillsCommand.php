<?php

namespace App\Console\Commands;

use App\Models\McpConnection;
use App\Models\User;
use App\Services\PaperTrading\RealDataPaperFills;
use Illuminate\Console\Command;

class McpPaperFillsCommand extends Command
{
    protected $signature = 'mcp:paper-fills {--account= : Local operator-owned connection ID} {--order= : Existing real-data paper order ID}';

    protected $description = 'Evaluate one paper order against stored broker observations; no network calls';

    public function handle(RealDataPaperFills $fills): int
    {
        if (! config('paper.real_data_fills_enabled')) {
            $this->error('Real-data paper fills are disabled.');

            return self::FAILURE;
        }
        $operators = User::whereIn('email', config('mcp.operator_emails'))->pluck('id');
        $connection = McpConnection::whereIn('user_id', $operators)->whereKey($this->option('account'))->whereNotNull('bound_account_id')->first();
        if (! $connection || ! ctype_digit((string) $this->option('order'))) {
            $this->error('Select an operator-owned bound connection and an existing paper order.');

            return self::FAILURE;
        }
        $order = $fills->process($connection, $connection->user_id, (int) $this->option('order'));
        $this->line(json_encode(['order_id' => $order->id, 'status' => $order->status, 'filled_quantity' => $order->filled_quantity,
            'mode' => 'real_data_paper_simulation'], JSON_THROW_ON_ERROR));

        return self::SUCCESS; // Successful processing can explicitly remain unfilled.
    }
}
