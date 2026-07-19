<?php

namespace App\Console\Commands;

use App\Enums\BrokerType;
use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Console\Command;
use Throwable;

class BrokerHarvestCommand extends Command
{
    protected $signature = 'broker:harvest
        {--broker= : Broker (coinbase|robinhood)}
        {--credential= : Broker credential ID}
        {--timeframes=1d,4h : Comma-separated market-data timeframes}
        {--interval=60 : Seconds between cycles}
        {--max-cycles=0 : 0 means run forever}
        {--max-runtime-seconds=0 : 0 means no runtime limit}
        {--sleep-on-error=20 : Seconds to sleep after a failed cycle}';

    protected $description = 'Run continuous broker sync cycles to keep market/account data hot.';

    public function handle(Dispatcher $dispatcher, BrokerSyncJobFactory $jobFactory): int
    {
        $broker = BrokerType::tryFrom((string) $this->option('broker')) ?? BrokerType::default();
        $credentialId = $this->option('credential') !== null ? (int) $this->option('credential') : null;
        $timeframes = $this->parseTimeframes((string) $this->option('timeframes'));
        if ($timeframes === []) {
            $this->error('No valid timeframes provided. Supported values: 1d,4h');

            return self::FAILURE;
        }

        $intervalSeconds = max(5, (int) $this->option('interval'));
        $maxCycles = max(0, (int) $this->option('max-cycles'));
        $maxRuntimeSeconds = max(0, (int) $this->option('max-runtime-seconds'));
        $sleepOnErrorSeconds = max(0, (int) $this->option('sleep-on-error'));

        $this->info(sprintf(
            'Starting broker harvest loop: broker=%s timeframes=%s interval=%ss maxCycles=%d maxRuntime=%ss',
            $broker->value,
            implode(',', $timeframes),
            $intervalSeconds,
            $maxCycles,
            $maxRuntimeSeconds,
        ));

        $startedAt = microtime(true);
        $cycle = 0;
        $successfulCycles = 0;

        while (true) {
            if ($this->shouldStop($cycle, $maxCycles, $startedAt, $maxRuntimeSeconds)) {
                break;
            }

            $cycle++;
            $cycleStartedAt = microtime(true);

            try {
                $dispatchedJobs = $this->runCycle(
                    dispatcher: $dispatcher,
                    jobFactory: $jobFactory,
                    broker: $broker,
                    credentialId: $credentialId,
                    timeframes: $timeframes,
                );
                $successfulCycles++;
                $durationSeconds = round(microtime(true) - $cycleStartedAt, 2);
                $this->line(sprintf(
                    '[cycle=%d] ok jobs=%d duration=%.2fs',
                    $cycle,
                    $dispatchedJobs,
                    $durationSeconds,
                ));
            } catch (Throwable $exception) {
                $durationSeconds = round(microtime(true) - $cycleStartedAt, 2);
                $this->error(sprintf(
                    '[cycle=%d] failed after %.2fs: %s',
                    $cycle,
                    $durationSeconds,
                    $exception->getMessage(),
                ));

                if ($this->shouldStop($cycle, $maxCycles, $startedAt, $maxRuntimeSeconds)) {
                    break;
                }

                if ($sleepOnErrorSeconds > 0) {
                    sleep($sleepOnErrorSeconds);
                }

                continue;
            }

            if ($this->shouldStop($cycle, $maxCycles, $startedAt, $maxRuntimeSeconds)) {
                break;
            }

            $sleepSeconds = max(0, $intervalSeconds - (int) ceil(microtime(true) - $cycleStartedAt));
            if ($sleepSeconds > 0) {
                sleep($sleepSeconds);
            }
        }

        $elapsed = round(microtime(true) - $startedAt, 2);
        $this->info(sprintf(
            'Harvest loop stopped. cycles=%d successful=%d elapsed=%.2fs',
            $cycle,
            $successfulCycles,
            $elapsed,
        ));

        return $successfulCycles > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<int, string>  $timeframes
     */
    private function runCycle(
        Dispatcher $dispatcher,
        BrokerSyncJobFactory $jobFactory,
        BrokerType $broker,
        ?int $credentialId,
        array $timeframes,
    ): int {
        $primaryTimeframe = $timeframes[0];
        $jobs = $jobFactory->make($broker, $credentialId, $primaryTimeframe);
        $dispatched = 0;

        foreach ($jobs as $job) {
            $dispatcher->dispatchSync($job);
            $dispatched++;
        }

        foreach (array_slice($timeframes, 1) as $timeframe) {
            $dispatcher->dispatchSync($jobFactory->marketDataJob($broker, $credentialId, $timeframe));
            $dispatched++;
        }

        return $dispatched;
    }

    private function shouldStop(
        int $cycle,
        int $maxCycles,
        float $startedAt,
        int $maxRuntimeSeconds,
    ): bool {
        if ($maxCycles > 0 && $cycle >= $maxCycles) {
            return true;
        }

        return $maxRuntimeSeconds > 0 && (microtime(true) - $startedAt) >= $maxRuntimeSeconds;
    }

    /**
     * @return array<int, string>
     */
    private function parseTimeframes(string $input): array
    {
        $allowed = ['1d', '4h'];

        return collect(explode(',', $input))
            ->map(fn (string $value): string => strtolower(trim($value)))
            ->filter(fn (string $value): bool => in_array($value, $allowed, true))
            ->unique()
            ->values()
            ->all();
    }
}
