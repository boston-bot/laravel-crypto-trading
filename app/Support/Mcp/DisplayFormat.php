<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Throwable;

/** Presentation only. Values are grouped as strings and never rounded or coerced through floats. */
final class DisplayFormat
{
    public const TIMEZONE = 'America/Chicago';

    private const TONES = [
        'ok' => ['ok', 'fresh', 'complete', 'completed', 'succeeded', 'delivered', 'regular', 'connected', 'enabled', 'ready', 'resolved', 'filled', 'active', 'supported', 'clear'],
        'warn' => ['stale', 'warning', 'source_time_unknown', 'unknown', 'unconfigured', 'pending', 'partially_filled', 'unconfirmed', 'before_open', 'after_close',
            'early_close', 'holiday', 'watch', 'skipped', 'budget_reduced', 'review_required', 'not evaluated', 'insufficient_evidence'],
        'bad' => ['failed', 'critical', 'error', 'unavailable', 'open', 'rejected', 'revoked', 'disconnected', 'invalidated', 'triggered', 'breached'],
        'info' => ['acknowledged', 'queued', 'confirmed', 'running', 'staged', 'processing', 'dispatched', 'info', 'manual'],
    ];

    /**
     * Display rounding with exact decimal arithmetic ("1234.567" → "$1,234.57"); callers keep the exact value visible on hover.
     * Unexpected formats are shown verbatim, never hidden.
     */
    public static function money(?string $value, int $scale = 2): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            return $value;
        }
        $parts = explode('.', (string) BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_EVEN));
        $negative = str_starts_with($parts[0], '-');

        return ($negative ? '-' : '').'$'.self::group(ltrim($parts[0], '-')).(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    /** Lossless: only trailing fractional zeros are removed ("60.000" → "60", "45.000125000" → "45.000125"). */
    public static function quantity(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^-?\d+\.\d+$/D', $value)) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.');
    }

    /** Percentage width for a proportional bar; display only, with exact decimal division. */
    public static function share(?string $part, ?string $total): ?string
    {
        if ($part === null || $total === null || ! preg_match('/^\d+(?:\.\d+)?$/D', $part) || ! preg_match('/^\d+(?:\.\d+)?$/D', $total)) {
            return null;
        }
        $whole = BigDecimal::of($total);
        if ($whole->isZero()) {
            return null;
        }

        return (string) BigDecimal::of($part)->multipliedBy(100)->dividedBy($whole, 1, RoundingMode::HALF_EVEN);
    }

    public static function tone(?string $status): string
    {
        $status = strtolower(trim((string) $status));
        foreach (self::TONES as $tone => $values) {
            if (in_array($status, $values, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }

    /** "market_session_unknown" → "Market session unknown"; the raw code stays visible beside it. */
    public static function humanize(string $code): string
    {
        return ucfirst(str_replace(['_', ':'], [' ', ': '], $code));
    }

    /** @return array{iso: string, local: string}|null */
    public static function time(DateTimeInterface|string|null $at, string $format = 'M j, H:i:s T'): ?array
    {
        if ($at === null || $at === '') {
            return null;
        }
        $iso = $at instanceof DateTimeInterface ? CarbonImmutable::instance($at)->toIso8601String() : $at;
        try {
            $parsed = $at instanceof DateTimeInterface ? CarbonImmutable::instance($at) : CarbonImmutable::parse($at);
        } catch (Throwable) {
            return ['iso' => $iso, 'local' => $iso];
        }

        return ['iso' => $iso, 'local' => $parsed->setTimezone(self::TIMEZONE)->format($format)];
    }

    private static function group(string $digits): string
    {
        $digits = ltrim($digits, '0') ?: '0';

        return strrev(implode(',', str_split(strrev($digits), 3)));
    }
}
