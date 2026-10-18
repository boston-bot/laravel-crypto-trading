<?php

declare(strict_types=1);

namespace App\Support\Mcp;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use JsonSerializable;

/** New domain boundary: only quoted, plain base-ten decimals are accepted. */
final readonly class Decimal implements JsonSerializable
{
    private function __construct(private BigDecimal $value) {}

    public static function from(mixed $value): self
    {
        if (! is_string($value) || ! preg_match('/^-?\d+(?:\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException('Expected a quoted plain decimal string.');
        }

        return new self(BigDecimal::of($value));
    }

    public function plus(self $other): self
    {
        return new self($this->value->plus($other->value));
    }

    public function minus(self $other): self
    {
        return new self($this->value->minus($other->value));
    }

    public function multipliedBy(self $other): self
    {
        return new self($this->value->multipliedBy($other->value));
    }

    public function dividedBy(self $other, int $scale, RoundingMode $mode = RoundingMode::HALF_EVEN): self
    {
        return new self($this->value->dividedBy($other->value, $scale, $mode));
    }

    public function compareTo(self $other): int
    {
        return $this->value->compareTo($other->value);
    }

    public function rounded(int $scale, RoundingMode $mode): self
    {
        return new self($this->value->toScale($scale, $mode));
    }

    public function jsonSerialize(): string
    {
        return (string) $this->value;
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
