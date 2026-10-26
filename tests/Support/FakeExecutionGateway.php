<?php

namespace Tests\Support;

use App\Models\Execution\OrderIntent;
use App\Models\McpConnection;
use App\Services\Execution\ExecutionGateway;

/** Records calls and returns scripted broker answers. Never contacts a broker. */
final class FakeExecutionGateway implements ExecutionGateway
{
    public array $calls = [];

    public array $review = ['order_checks' => [], 'customer_approval_required' => true];

    /** A result array, or a Throwable thrown from place(). */
    public mixed $place = null;

    public ?\Throwable $openFailure = null;

    public ?\Closure $beforeReview = null;

    public function within(McpConnection $connection, callable $work): mixed
    {
        $this->calls[] = 'open';
        if ($this->openFailure) {
            throw $this->openFailure;
        }

        return $work();
    }

    public function review(McpConnection $connection, OrderIntent $intent): array
    {
        $this->calls[] = 'review';
        ($this->beforeReview) && ($this->beforeReview)();

        return $this->review;
    }

    public function place(McpConnection $connection, OrderIntent $intent): array
    {
        $this->calls[] = 'place:'.$intent->idempotency_key;
        if ($this->place instanceof \Throwable) {
            throw $this->place;
        }

        return $this->place ?? ['kind' => 'order', 'order_id' => 'fake-order-'.$intent->id];
    }
}
