<?php

namespace App\Services\Operations;

use RuntimeException;

/** Carries a stable, non-sensitive error code only; never provider responses, URLs or tokens. */
final class NotificationFailure extends RuntimeException {}
