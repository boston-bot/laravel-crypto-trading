<?php

use App\Models\McpConnection;
use App\Services\PaperTrading\PaperMandates;
use App\Support\Mcp\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = DB::connection();
TestDatabaseGuard::check($database->getDriverName(), $database->getDatabaseName(), 'postgres');
Http::preventStrayRequests();
$connection = McpConnection::findOrFail((int) $argv[1]);
try {
    app(PaperMandates::class)->append($connection, $connection->user_id, 0, $argv[2], ['initial_cash' => '10000.00'], 'Concurrent fixture.');
    echo 'saved';
} catch (HttpException $exception) {
    if ($exception->getStatusCode() !== 409) {
        throw $exception;
    }
    echo 'conflict';
}
