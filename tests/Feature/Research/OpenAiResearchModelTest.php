<?php

namespace Tests\Feature\Research;

use App\Services\Research\ModelRequest;
use App\Services\Research\OpenAiResearchModel;
use App\Services\Research\ResearchError;
use App\Services\Research\ResearchFailure;
use App\Services\Research\ResearchPrompt;
use App\Services\Research\ResearchSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\ResearchFixture;
use Tests\TestCase;

/** Contract shape from the provider's documented Responses API, exercised only with fake HTTP. */
class OpenAiResearchModelTest extends TestCase
{
    private function request(): ModelRequest
    {
        return new ModelRequest('synthetic-model', ResearchPrompt::instructions(), ResearchPrompt::input(['subject' => 'SYNTH', 'sources' => []]),
            ResearchSchema::schema(), ResearchSchema::NAME, 2000);
    }

    private function body(array $output, string $status = 'completed'): array
    {
        return ['id' => 'resp_synthetic', 'status' => $status, 'model' => 'synthetic-model-2026', 'output' => $output, 'usage' => ['input_tokens' => 321, 'output_tokens' => 45, 'total_tokens' => 366]];
    }

    public function test_request_is_strict_unstored_tool_free_and_parses_text_and_usage(): void
    {
        ResearchFixture::enable();
        Http::fake([OpenAiResearchModel::ENDPOINT => Http::response($this->body([['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => '{"a":'], ['type' => 'output_text', 'text' => '1}']]]]))]);
        $response = (new OpenAiResearchModel)->complete($this->request());
        $this->assertSame(['completed', '{"a":1}', 321, 45, 'synthetic-model-2026'], [$response->status, $response->text, $response->inputTokens, $response->outputTokens, $response->model]);
        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $request->url() === OpenAiResearchModel::ENDPOINT && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer synthetic-key')
                && $body['store'] === false && $body['text']['format']['type'] === 'json_schema' && $body['text']['format']['strict'] === true
                && $body['text']['format']['schema'] === ResearchSchema::schema() && ! array_key_exists('tools', $body) && ! array_key_exists('tool_choice', $body)
                && $body['max_output_tokens'] === 2000 && $body['input'][0]['content'][0]['type'] === 'input_text';
        });
    }

    public function test_refusal_incomplete_and_tool_calls_are_not_accepted_as_research(): void
    {
        ResearchFixture::enable();
        Http::fakeSequence(OpenAiResearchModel::ENDPOINT)
            ->push($this->body([['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Synthetic refusal.']]]]))
            ->push($this->body([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"partial']]]], 'incomplete'))
            ->push($this->body([['type' => 'function_call', 'name' => 'place_order', 'arguments' => '{}']]))
            ->push($this->body([['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{}']]]], 'failed'));
        $model = new OpenAiResearchModel;
        $refused = $model->complete($this->request());
        $this->assertSame(['refused', null], [$refused->status, $refused->text]);
        $this->assertSame('incomplete', $model->complete($this->request())->status);
        foreach ([ResearchError::MalformedOutput, ResearchError::Transport] as $reason) {
            try {
                $model->complete($this->request());
                $this->fail('Unexpected provider output accepted.');
            } catch (ResearchFailure $failure) {
                $this->assertSame($reason, $failure->reason);
            }
        }
    }

    public function test_http_failures_are_typed_and_missing_credential_sends_nothing(): void
    {
        config(['research.api_key' => null]);
        Http::fake();
        $this->assertFailure(ResearchError::CredentialMissing);
        Http::assertNothingSent();
        ResearchFixture::enable();
        foreach ([[401, ResearchError::Authentication, null], [403, ResearchError::Authentication, null], [400, ResearchError::RequestRejected, null],
            [404, ResearchError::RequestRejected, null], [422, ResearchError::RequestRejected, null], [429, ResearchError::RateLimited, 30], [500, ResearchError::Transport, null]] as [$status, $reason, $retry]) {
            $this->fake(Http::response(['error' => ['message' => 'synthetic upstream secret']], $status, ['Retry-After' => '30']));
            $failure = $this->assertFailure($reason);
            $this->assertSame($retry, $failure->retryAfter);
            $this->assertStringNotContainsString('synthetic upstream secret', $failure->getMessage());
        }
        $this->fake(fn () => throw new ConnectionException('synthetic timeout'));
        $this->assertFailure(ResearchError::Timeout);
        $this->fake(Http::response(str_repeat('a', 1024 * 1024 + 1)));
        $this->assertFailure(ResearchError::MalformedOutput);
    }

    private function fake(mixed $response): void
    {
        Http::swap((new Factory)->preventStrayRequests());
        Http::fake([OpenAiResearchModel::ENDPOINT => $response]);
    }

    private function assertFailure(ResearchError $reason): ResearchFailure
    {
        try {
            (new OpenAiResearchModel)->complete($this->request());
        } catch (ResearchFailure $failure) {
            $this->assertSame($reason, $failure->reason);

            return $failure;
        }
        $this->fail('Expected provider failure.');
    }
}
