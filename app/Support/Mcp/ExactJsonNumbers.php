<?php

declare(strict_types=1);

namespace App\Support\Mcp;

/** Retain JSON numeric lexemes as strings before PHP can convert decimal numbers to binary floats. */
final class ExactJsonNumbers
{
    public static function decode(string $json): array
    {
        // Skip complete JSON string tokens, including their escaped quotes/backslashes.
        $pattern = <<<'REGEX'
~"(?:[^"\\]|\\.)*"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?~s
REGEX;
        $quoted = preg_replace_callback($pattern, fn ($match) => '"'.$match[0].'"', $json);
        $value = json_decode($quoted, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new \InvalidArgumentException('Expected a JSON container.');
        }

        return $value;
    }
}
