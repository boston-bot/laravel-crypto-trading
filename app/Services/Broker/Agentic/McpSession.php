<?php

namespace App\Services\Broker\Agentic;

use GuzzleHttp\Psr7\HttpFactory;
use Mcp\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Exception\TimeoutException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\ProtocolVersion;

final class McpSession
{
    private Client $client;

    public function __construct(private RpcHttpClient $http)
    {
        $factory = new HttpFactory;
        $this->client = Client::builder()->setClientInfo('local-investing-monitor', '0.1.0')
            ->setProtocolVersion(ProtocolVersion::V2025_11_25)->setInitTimeout(10)->setRequestTimeout(10)->setMaxRetries(0)->build();
        $http->protocolVersion = fn () => $this->client->getProtocolVersion()?->value;
        $this->invoke(fn () => $this->client->connect(new HttpTransport(TrustedHttp::RESOURCE, httpClient: $http,
            requestFactory: $factory, streamFactory: $factory, maxSseBufferBytes: 8 * 1024 * 1024)));
    }

    public function protocol(): string
    {
        return $this->client->getProtocolVersion()->value;
    }

    public function listTools(?string $cursor = null): array
    {
        $result = $this->invoke(fn () => $this->client->listTools($cursor));

        return ['tools' => array_map(fn ($tool) => $tool->jsonSerialize(), $result->tools), 'nextCursor' => $result->nextCursor];
    }

    public function call(string $tool, array $arguments): array
    {
        $result = $this->invoke(fn () => $this->client->callTool($tool, $arguments));
        if ($result->isError) {
            throw new ReadFailure(ReadError::Malformed);
        }
        if (is_array($result->structuredContent)) {
            return $result->structuredContent;
        }
        if (count($result->content) === 1 && $result->content[0] instanceof TextContent) {
            try {
                // JSON decimal numbers must not be silently converted into new-domain decimal strings.
                $decoded = json_decode($result->content[0]->text, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    return $decoded;
                }
            } catch (\JsonException) {
            }
        }
        throw new ReadFailure(ReadError::Malformed);
    }

    public function close(): void
    {
        $this->client->disconnect();
    }

    private function invoke(callable $action): mixed
    {
        $this->http->failure = null;
        try {
            return $action();
        } catch (\Throwable $exception) {
            throw $this->http->failure ?? new ReadFailure($exception instanceof TimeoutException ? ReadError::Timeout : ReadError::Malformed);
        }
    }
}
