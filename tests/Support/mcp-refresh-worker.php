<?php

use App\Models\McpConnection;
use App\Services\Broker\Agentic\OAuthService;
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
config(['robinhood_mcp.enabled' => true]);
Http::preventStrayRequests();
Http::fake(['https://api.robinhood.com/oauth2/token/' => function () use ($argv) {
    file_put_contents($argv[2], "refresh\n", FILE_APPEND | LOCK_EX);
    usleep(250000);

    return Http::response(McpFixture::tokens());
}]);
$record = McpConnection::findOrFail((int) $argv[1]);
echo hash('sha256', app(OAuthService::class)->accessToken($record));
