<?php

namespace App\Services\Broker\Agentic;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** The SDK cannot send arbitrary RPC methods, tools, headers or URLs through this boundary. */
final class RpcHttpClient implements ClientInterface
{
    public ?ReadFailure $failure = null;

    public ?\Closure $protocolVersion = null;

    public function __construct(private TrustedHttp $http, private string $token, private array $allowedTools) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            if ((string) $request->getUri() !== TrustedHttp::RESOURCE || ! in_array($request->getMethod(), ['POST', 'DELETE'], true)) {
                throw new ReadFailure(ReadError::Forbidden);
            }
            if ($request->getMethod() === 'POST') {
                $rpc = json_decode((string) $request->getBody(), true, 64, JSON_THROW_ON_ERROR);
                if (! in_array($rpc['method'] ?? null, ['initialize', 'notifications/initialized', 'tools/list', 'tools/call'], true)) {
                    throw new ReadFailure(ReadError::Forbidden);
                }
                if ($rpc['method'] === 'tools/call' && ! in_array($rpc['params']['name'] ?? null, $this->allowedTools, true)) {
                    throw new ReadFailure(ReadError::Forbidden);
                }
            }
            $headers = ['Authorization' => 'Bearer '.$this->token];
            foreach (['Content-Type', 'Accept', 'Mcp-Session-Id', 'MCP-Protocol-Version'] as $name) {
                if ($request->hasHeader($name)) {
                    $headers[$name] = $request->getHeaderLine($name);
                }
            }
            $protocol = $this->protocolVersion ? ($this->protocolVersion)() : null;
            if ($protocol) {
                $headers['MCP-Protocol-Version'] = $protocol;
            }
            $response = $this->http->send($request->getMethod(), TrustedHttp::RESOURCE, ['headers' => $headers, 'body' => (string) $request->getBody()]);
            TrustedHttp::checkStatus($response);
            $psr = $response->toPsrResponse();
            $psr->getBody()->rewind();

            return $psr;
        } catch (ReadFailure $failure) {
            $this->failure = $failure;
            throw $failure;
        } catch (\Throwable) {
            $this->failure = new ReadFailure(ReadError::Malformed);
            throw $this->failure;
        }
    }
}
