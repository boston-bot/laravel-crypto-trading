<?php

namespace Tests\Feature\Trading;

use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BrokerHarvestCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_single_cycle_and_dispatches_primary_and_extra_timeframes(): void
    {
        $primaryJobA = (object) ['id' => 'primary-a'];
        $primaryJobB = (object) ['id' => 'primary-b'];
        $extraMarketDataJob = (object) ['id' => 'extra-4h'];

        $factory = Mockery::mock(BrokerSyncJobFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->andReturn([$primaryJobA, $primaryJobB]);
        $factory->shouldReceive('marketDataJob')
            ->once()
            ->andReturn($extraMarketDataJob);
        $this->app->instance(BrokerSyncJobFactory::class, $factory);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchSync')->once()->with($primaryJobA);
        $dispatcher->shouldReceive('dispatchSync')->once()->with($primaryJobB);
        $dispatcher->shouldReceive('dispatchSync')->once()->with($extraMarketDataJob);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->artisan('broker:harvest --broker=coinbase --timeframes=1d,4h --max-cycles=1 --interval=5')
            ->expectsOutputToContain('Starting broker harvest loop')
            ->expectsOutputToContain('Harvest loop stopped.')
            ->assertExitCode(0);
    }

    public function test_command_fails_when_no_valid_timeframes_are_provided(): void
    {
        $this->artisan('broker:harvest --timeframes=1m,5m --max-cycles=1')
            ->expectsOutputToContain('No valid timeframes provided. Supported values: 1d,4h')
            ->assertExitCode(1);
    }
}
