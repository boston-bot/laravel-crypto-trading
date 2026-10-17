<?php

namespace App\Services\Research;

/** Text-in, schema-constrained text-out. No tools, broker access or configuration writes exist on this boundary. */
interface ResearchModel
{
    /** @throws ResearchFailure */
    public function complete(ModelRequest $request): ModelResponse;
}
