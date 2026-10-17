<?php

namespace App\Services\Broker\Agentic;

enum ReadError: string
{
    case Disabled = 'disabled';
    case Authentication = 'authentication_required';
    case Forbidden = 'forbidden';
    case RateLimited = 'rate_limited';
    case Timeout = 'timeout';
    case Transport = 'transport_unavailable';
    case Malformed = 'malformed_response';
    case Unsupported = 'unsupported_capability';
    case SchemaChanged = 'schema_changed';
    case AccountMismatch = 'account_mismatch';
    case Stale = 'stale_data';
    case Incomplete = 'incomplete_pagination';
    case OAuth = 'oauth_failed';
}
