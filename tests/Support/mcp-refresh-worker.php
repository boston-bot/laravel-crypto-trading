<?php

use App\Models\McpConnection;
use App\Services\Broker\Agentic\OAuthService;
use App\Services\Broker\Agentic\SessionFactory;
use App\Services\Broker\Agentic\TrustedHttp;
use App\Support\Mcp\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\McpFixture;

// Used only by the isolated PostgreSQL concurrency test. Never loads broker credentials.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
TestDatabaseGuard::check($connection->getDriverName(), $connection->getDatabaseName(), 'postgres');
config(['robinhood_mcp.enabled' => true, 'mcp.brokerage_writes_enabled' => true]);
Http::preventStrayRequests();
Http::fake(['https://api.robinhood.com/oauth2/token/' => function () use ($argv) {
    file_put_contents($argv[2], "refresh\n", FILE_APPEND | LOCK_EX);
    usleep(250000);

    return Http::response(McpFixture::tokens());
}, TrustedHttp::RESOURCE => function ($request) {
    if ($request->method() === 'DELETE' || str_starts_with($request['method'] ?? '', 'notifications/')) {
        return Http::response(null, 202);
    }
    if (($request['method'] ?? '') !== 'initialize' || $request->header('Authorization') !== ['Bearer synthetic-access']) {
        throw new RuntimeException('Unexpected request in isolated session-refresh fixture.');
    }

    return Http::response(['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => [
        'protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => new stdClass],
        'serverInfo' => ['name' => 'synthetic', 'version' => '1'],
    ]]);
}]);
$record = McpConnection::findOrFail((int) $argv[1]);
$mode = $argv[3] ?? 'token';
if ($mode === 'token') {
    echo hash('sha256', app(OAuthService::class)->accessToken($record));
} else {
    // Initialize and close only. Any tool call, including review/place, is refused by this fixture.
    $session = $mode === 'execution' ? app(SessionFactory::class)->openExecution($record) : app(SessionFactory::class)->open($record);
    echo $session->protocol();
    $session->close();
}
