<?php

namespace App\Services\Research;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Responses API with strict JSON-schema output. The endpoint is fixed, no tools are offered, and
 * the provider is asked not to store the request. Any non-message output item is refused rather than executed.
 */
final class OpenAiResearchModel implements ResearchModel
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';

    public function complete(ModelRequest $request): ModelResponse
    {
        $key = config('research.api_key');
        if (! is_string($key) || $key === '') {
            throw new ResearchFailure(ResearchError::CredentialMissing);
        }
        try {
            $response = Http::timeout((int) config('research.timeout_seconds'))->connectTimeout(5)->withToken($key)->acceptJson()
                ->withOptions(['allow_redirects' => false])->post(self::ENDPOINT, [
                    'model' => $request->model,
                    'instructions' => $request->instructions,
                    'input' => [['role' => 'user', 'content' => [['type' => 'input_text', 'text' => $request->input]]]],
                    'text' => ['format' => ['type' => 'json_schema', 'name' => $request->schemaName, 'schema' => $request->schema, 'strict' => true]],
                    'max_output_tokens' => $request->maxOutputTokens,
                    'store' => false,
                ]);
        } catch (ConnectionException) {
            // The provider may have processed the request; budget settlement treats this as unreconciled.
            throw new ResearchFailure(ResearchError::Timeout);
        }
        if (strlen($response->body()) > 1024 * 1024) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
        if (! $response->successful()) {
            throw new ResearchFailure(match (true) {
                in_array($response->status(), [401, 403], true) => ResearchError::Authentication,
                // Rejected as invalid (for example an inaccessible model or schema): unbilled, and a configuration problem.
                in_array($response->status(), [400, 404, 422], true) => ResearchError::RequestRejected,
                $response->status() === 429 => ResearchError::RateLimited,
                default => ResearchError::Transport,
            }, $response->status() === 429 ? min(3600, max(1, (int) $response->header('Retry-After'))) : null);
        }
        $json = $response->json();
        if (! is_array($json) || ! is_array($json['output'] ?? null)) {
            throw new ResearchFailure(ResearchError::MalformedOutput);
        }
        $text = null;
        $refused = false;
        foreach ($json['output'] as $item) {
            $type = is_array($item) ? ($item['type'] ?? null) : null;
            if ($type === 'reasoning') {
                continue;
            }
            if ($type !== 'message' || ! is_array($item['content'] ?? null)) {
                throw new ResearchFailure(ResearchError::MalformedOutput);
            }
            foreach ($item['content'] as $part) {
                if (($part['type'] ?? null) === 'refusal') {
                    $refused = true;
                } elseif (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                    $text = ($text ?? '').$part['text'];
                } else {
                    throw new ResearchFailure(ResearchError::MalformedOutput);
                }
            }
        }
        $status = match (true) {
            $refused => 'refused',
            ($json['status'] ?? null) === 'completed' => 'completed',
            ($json['status'] ?? null) === 'incomplete' => 'incomplete',
            default => throw new ResearchFailure(ResearchError::Transport),
        };
        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $tokens = fn (string $key) => is_int($usage[$key] ?? null) && $usage[$key] >= 0 ? $usage[$key] : null;

        return new ModelResponse($status, $refused ? null : $text, $tokens('input_tokens'), $tokens('output_tokens'),
            is_string($json['model'] ?? null) ? $json['model'] : $request->model);
    }
}
