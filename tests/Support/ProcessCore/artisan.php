<?php

declare(strict_types=1);

use App\Services\Procurement\Process\Execution\StageRegistry;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArgvInput;
use Tests\Support\ProcessCore\AcceptanceRuntime;
use Tests\Support\ProcessCore\InstallClock;
use Tests\Support\ProcessCore\PipelineHorizonRuntime;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestFilesystemIsolation;

if (getenv('PROCESS_CORE_TEST') !== '1' || dirname(__DIR__, 3) !== '/var/www/html' || ! is_file('/.dockerenv')) {
    throw new RuntimeException('Process-core CLI разрешён только внутри отдельного testing container.');
}

putenv('PROCESS_CORE_PROCESS=runtime-'.getmypid());
require dirname(__DIR__, 2).'/bootstrap.php';

// Settings migrations write timestamps too: freeze before the kernel and migrations.
InstallClock::configure();

$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app->instance(StageRegistry::class, new StageRegistry);

TestFilesystemIsolation::configure($app);
TestEnvironmentGuard::assertBootstrapTestDatabase($app);
TestEnvironmentGuard::assertIsolatedTestFilesystem($app);
Http::preventStrayRequests();

// Only the fixture queue is consumed. Existing domain jobs remain inactive.
config(['horizon.memory_limit' => 128, 'horizon.environments.testing' => [
    'supervisor-core-fixtures' => [
        'connection' => 'redis',
        'queue' => ['process-core-fixtures'],
        'balance' => 'simple',
        'maxProcesses' => 1,
        'tries' => 1,
        'timeout' => 30,
    ],
]]);

if (getenv('PROCESS_CORE_ACCEPTANCE_FILE') !== false) {
    AcceptanceRuntime::boot((string) getenv('PROCESS_CORE_ACCEPTANCE_FILE'));
}

if (getenv('PROCESS_CORE_PIPELINE_FILE') !== false) {
    PipelineHorizonRuntime::boot((string) getenv('PROCESS_CORE_PIPELINE_FILE'));
}

$status = $app->handleCommand(new ArgvInput);

exit($status);
