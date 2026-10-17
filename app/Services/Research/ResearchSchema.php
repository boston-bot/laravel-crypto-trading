<?php

namespace App\Services\Research;

use App\Support\Mcp\Decimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JsonException;

/** Provider-side structured output constrains format only. These application checks decide validity. */
final class ResearchSchema
{
    public const NAME = 'research_v1';

    private const ASSESSMENTS = ['supported', 'weakened', 'invalidated', 'insufficient_evidence', 'no_thesis'];

    private const STANCES = ['candidate', 'wait', 'hold', 'review'];

    private const HORIZONS = ['days', 'weeks', 'months', 'years'];

    private const INVALIDATIONS = ['price', 'completed_session_close', 'fundamental_event', 'manual_review'];

    // Conservative heuristics: a false positive fails safe as a visible rejected run. The structural guarantee is
    // that the model has no tools and its output is stored as non-executable data.
    private const EXECUTION_PATTERNS = [
        '/\b(place|submit|execute|send|route|cancel)\b[^.\n]{0,30}\borders?\b/i',
        '/\b(execute|place|make)\s+(a|the|this)\s+trade\b/i',
        '/\b(buy|sell|short)\s+(\$?\d[\d,.]*\s*(shares?|units?)?|all|now|immediately|at (the )?market)\b/i',
        '/\b(call|invoke|use|run)\b[^.\n]{0,20}\b(tool|function)s?\b/i',
        '/\b\w*(place|review|cancel|submit|modify)_\w*\b/i',
        '/\b(change|raise|increase|lower|disable|enable|override|bypass|set|switch)\b[^.\n]{0,30}\b(risk limits?|position limits?|operating mode|execution mode|trading mode|approval (settings?|requirements?)|credentials?|permissions?|research budget|kill switch)\b/i',
        '/\b(bounded_auto|approval_required|brokerage_writes)\b/i',
        '/\bignore\b[^.\n]{0,40}\b(instructions?|rules?|polic(y|ies)|system)\b/i',
    ];

    /** Only widely supported strict-mode keywords; lengths, counts and semantics are enforced in validate(). */
    public static function schema(): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => $string];

        return ['type' => 'object', 'additionalProperties' => false,
            'required' => ['thesis_assessment', 'summary', 'material_facts', 'uncertainties', 'proposal'],
            'properties' => [
                'thesis_assessment' => ['type' => 'string', 'enum' => self::ASSESSMENTS],
                'summary' => $string,
                'material_facts' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['statement', 'source_ids', 'as_of'],
                    'properties' => ['statement' => $string, 'source_ids' => $strings, 'as_of' => $string]]],
                'uncertainties' => $strings,
                'proposal' => ['type' => 'object', 'additionalProperties' => false,
                    'required' => ['stance', 'symbol', 'catalyst', 'risks', 'horizon', 'invalidation', 'review_at'],
                    'properties' => [
                        'stance' => ['type' => 'string', 'enum' => self::STANCES],
                        'symbol' => $string,
                        'catalyst' => ['type' => ['string', 'null']],
                        'risks' => $strings,
                        'horizon' => ['type' => 'string', 'enum' => self::HORIZONS],
                        'invalidation' => ['type' => 'object', 'additionalProperties' => false,
                            'required' => ['type', 'operator', 'threshold', 'description'],
                            'properties' => ['type' => ['type' => 'string', 'enum' => self::INVALIDATIONS],
                                'operator' => ['type' => ['string', 'null']], 'threshold' => ['type' => ['string', 'null']], 'description' => $string]],
                        'review_at' => $string,
                    ]],
            ]];
    }

    /**
     * @param  array{allowed_symbols: list<string>, sources: array<string, string>, has_thesis: bool}  $context  source ID => trusted as-of time
     */
    public static function validate(?string $text, array $context): array
    {
        if ($text === null || strlen($text) > 64 * 1024) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
        try {
            $data = json_decode($text, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
        self::keys($data, ['thesis_assessment', 'summary', 'material_facts', 'uncertainties', 'proposal']);
        self::enum($data['thesis_assessment'], self::ASSESSMENTS);
        self::text($data['summary'], 1200);
        self::items($data['material_facts'], 12);
        foreach ($data['material_facts'] as $fact) {
            self::keys($fact, ['statement', 'source_ids', 'as_of']);
            self::text($fact['statement'], 500);
            self::items($fact['source_ids'], 5, 1);
            foreach ($fact['source_ids'] as $id) {
                self::text($id, 16);
            }
            self::time($fact['as_of']);
        }
        self::items($data['uncertainties'], 8);
        foreach ($data['uncertainties'] as $item) {
            self::text($item, 300);
        }
        $proposal = $data['proposal'];
        self::keys($proposal, ['stance', 'symbol', 'catalyst', 'risks', 'horizon', 'invalidation', 'review_at']);
        self::enum($proposal['stance'], self::STANCES);
        self::text($proposal['symbol'], 16);
        if ($proposal['catalyst'] !== null) {
            self::text($proposal['catalyst'], 300);
        }
        self::items($proposal['risks'], 8);
        foreach ($proposal['risks'] as $risk) {
            self::text($risk, 300);
        }
        self::enum($proposal['horizon'], self::HORIZONS);
        $invalidation = $proposal['invalidation'];
        self::keys($invalidation, ['type', 'operator', 'threshold', 'description']);
        self::enum($invalidation['type'], self::INVALIDATIONS);
        self::text($invalidation['description'], 300);
        foreach (['operator', 'threshold'] as $key) {
            if ($invalidation[$key] !== null) {
                self::text($invalidation[$key], 40);
            }
        }
        $reviewAt = self::time($proposal['review_at']);

        // Semantic checks: trusted source mapping, evidence-backed claims, symbols, timing and non-execution.
        $now = CarbonImmutable::now();
        foreach ($data['material_facts'] as $fact) {
            $latest = null;
            foreach ($fact['source_ids'] as $id) {
                if (! isset($context['sources'][$id])) {
                    throw new ResearchFailure(ResearchError::UnknownSource);
                }
                $asOf = CarbonImmutable::parse($context['sources'][$id]);
                $latest = $latest === null || $asOf->gt($latest) ? $asOf : $latest;
            }
            $claimed = CarbonImmutable::parse($fact['as_of']);
            if ($claimed->gt($latest) || $claimed->gt($now)) {
                throw new ResearchFailure(ResearchError::FactAfterSource);
            }
        }
        if (! in_array($proposal['symbol'], $context['allowed_symbols'], true)) {
            throw new ResearchFailure(ResearchError::UnsupportedSymbol);
        }
        $claims = in_array($data['thesis_assessment'], ['supported', 'weakened', 'invalidated'], true);
        if ((($claims || $proposal['stance'] === 'candidate') && $data['material_facts'] === []) ||
            ($claims && ! $context['has_thesis']) || ($data['thesis_assessment'] === 'no_thesis' && $context['has_thesis'])) {
            throw new ResearchFailure(ResearchError::UnsupportedClaim);
        }
        if (in_array($invalidation['type'], ['price', 'completed_session_close'], true)) {
            try {
                $valid = in_array($invalidation['operator'], ['lte', 'gte'], true) && Decimal::from($invalidation['threshold'])->compareTo(Decimal::from('0')) > 0;
            } catch (InvalidArgumentException) {
                $valid = false;
            }
            if (! $valid) {
                throw new ResearchFailure(ResearchError::InvalidInvalidation);
            }
        } elseif ($invalidation['operator'] !== null || $invalidation['threshold'] !== null) {
            throw new ResearchFailure(ResearchError::InvalidInvalidation);
        }
        if ($reviewAt->lte($now) || $reviewAt->gt($now->addDays(180))) {
            throw new ResearchFailure(ResearchError::InvalidReviewTime);
        }
        array_walk_recursive($data, function ($value): void {
            if (! is_string($value)) {
                return;
            }
            // Compatibility forms (for example full-width letters) and Unicode spaces cannot slip past the patterns.
            $text = class_exists(\Normalizer::class) ? (\Normalizer::normalize($value, \Normalizer::FORM_KC) ?: $value) : $value;
            $text = preg_replace('/\p{Z}+/u', ' ', $text) ?? $text;
            foreach (self::EXECUTION_PATTERNS as $pattern) {
                if (preg_match($pattern, $text)) {
                    throw new ResearchFailure(ResearchError::ExecutionInstruction);
                }
            }
        });

        return $data;
    }

    private static function keys(mixed $value, array $keys): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
    }

    private static function text(mixed $value, int $max): void
    {
        // Format characters (zero-width, bidirectional overrides) are rejected: they hide text from checks and reviewers.
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\p{Cf}/u', $value) !== 0) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
    }

    private static function items(mixed $value, int $max, int $min = 0): void
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) < $min || count($value) > $max) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
    }

    private static function enum(mixed $value, array $allowed): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
    }

    private static function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new ResearchFailure(ResearchError::InvalidTimestamp);
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            throw new ResearchFailure(ResearchError::InvalidTimestamp);
        }
    }
}
