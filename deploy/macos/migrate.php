<?php

// One-off CLI entry point; never cache owner credentials or expose exception details.
use App\Services\Operations\MigrationDeployment;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $mode = $argv[1] ?? '';
    if (count($argv) !== 2 || ! in_array($mode, ['--check', '--pretend', '--apply'], true)) {
        throw new RuntimeException('Invalid migration invocation.');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $deployment = $app->make(MigrationDeployment::class);
    if ($mode === '--check') {
        $pending = $deployment->pending();
        echo json_encode(['pending' => $pending], JSON_THROW_ON_ERROR).PHP_EOL;
        exit($pending === [] ? 0 : 2);
    }
    $file = getenv('MCP_MIGRATION_CREDENTIALS_FILE');
    if (! is_string($file) || $file === '') {
        throw new RuntimeException('Missing migration credentials path.');
    }
    echo $deployment->migrate($file, $mode === '--pretend');
} catch (Throwable) {
    fwrite(STDERR, "Schema deployment failed. Check the private credential file, verified owner/target, migration review and deployment lock. No services were restarted by this command.\n");
    exit(1);
}
