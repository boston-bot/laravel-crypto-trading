<?php

namespace App\Services\Research;

final readonly class ModelRequest
{
    public function __construct(
        public string $model,
        public string $instructions,
        public string $input,
        public array $schema,
        public string $schemaName,
        public int $maxOutputTokens,
    ) {}

    /** Byte length is a conservative upper bound for the input token count used in budget reservations. */
    public function inputBytes(): int
    {
        return strlen($this->instructions) + strlen($this->input) + strlen(json_encode($this->schema, JSON_THROW_ON_ERROR));
    }
}
