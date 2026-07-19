<?php

namespace App\Console\Commands;

use App\Services\MarketData\MarketEvidenceRepairService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class RepairMarketEvidenceCommand extends Command
{
    protected $signature = 'research:repair-market-evidence
        {--asset=* : Limit the plan to one or more asset symbols}
        {--start= : Inclusive UTC start time}
        {--end= : Exclusive UTC end time}
        {--execute : Apply the displayed bounded repair plan}';

    protected $description = 'Plan or explicitly execute a revision-preserving canonical candle repair';

    public function handle(MarketEvidenceRepairService $repairs): int
    {
        $start = $this->option('start') ? CarbonImmutable::parse((string) $this->option('start'), 'UTC')->utc() : null;
        $end = $this->option('end') ? CarbonImmutable::parse((string) $this->option('end'), 'UTC')->utc() : null;
        if ($start !== null && $end !== null && ! $start->lessThan($end)) {
            $this->error('--end must be later than --start.');

            return self::INVALID;
        }

        $plan = $repairs->plan((array) $this->option('asset'), $start, $end);
        $displayPlan = $plan;
        foreach ($displayPlan['assets'] as &$assetPlan) {
            $assetPlan['batch_count'] = count((array) ($assetPlan['batches'] ?? []));
            unset($assetPlan['batches']);
        }
        unset($assetPlan);
        $this->line(json_encode($displayPlan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($plan['assets'] === []) {
            $this->info('No malformed or missing canonical market evidence was found in the selected range.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('execute')) {
            $this->warn('Dry run only. No rows were changed. Re-run with --execute after reviewing this plan.');

            return self::SUCCESS;
        }

        try {
            $result = $repairs->execute($plan);
            $this->info(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Repair stopped after a batch rollback: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
