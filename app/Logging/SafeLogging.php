<?php

namespace App\Logging;

use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;

final class SafeLogging
{
    public function __invoke($logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            if (method_exists($handler, 'setFormatter')) {
                $handler->setFormatter(new JsonFormatter);
            }
        }
        // Arbitrary upstream prose, exception messages and HTTP payloads may contain secrets.
        $logger->pushProcessor(fn (LogRecord $record) => $record->with(
            message: 'application_event',
            context: $this->redact($record->context),
            extra: [],
        ));
    }

    public function redact(array $context): array
    {
        $safe = [];
        foreach (['event', 'status', 'error_code', 'run_id'] as $key) {
            if (isset($context[$key]) && is_string($context[$key]) && preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $context[$key])) {
                $safe[$key] = $context[$key];
            }
        }

        return $safe;
    }
}
