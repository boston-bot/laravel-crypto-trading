<?php

namespace Tests\Feature\Trading;

use App\Services\Broker\BrokerSyncJobFactory;
use Illuminate\Bus\Dispatcher;
use Mockery;
use Tests\TestCase;

class BrokerSyncEndpointTest extends TestCase
{
    public function test_sync_endpoint_can_execute_jobs_synchronously(): void
    {
        $jobA = (object) ['id' => 'job-a'];
        $jobB = (object) ['id' => 'job-b'];

        $factory = Mockery::mock(BrokerSyncJobFactory::class);
        $factory->shouldReceive('make')
            ->once()
            ->andReturn([$jobA, $jobB]);
        $this->app->instance(BrokerSyncJobFactory::class, $factory);

        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchSync')->once()->with($jobA);
        $dispatcher->shouldReceive('dispatchSync')->once()->with($jobB);
        $this->app->instance(Dispatcher::class, $dispatcher);

        $this->postJson('/api/broker/sync?broker=coinbase&sync=1')
            ->assertOk()
            ->assertJsonPath('message', 'Coinbase sync jobs executed synchronously.');
    }
}
