<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Platforms\Eis\EisCardRoutes;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Jobs\Procurement\Process\ExecuteWorkJob;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Procurement\Acquisition\AcquisitionHandler;
use App\Services\Procurement\Acquisition\AcquisitionValidation;
use App\Services\Procurement\Document\RuntimeDownloadHandler;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Network\UrlPolicy;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Platform\PlatformRegistry;
use App\Services\Procurement\Process\StageRegistry;
use App\Services\Procurement\Proxy\ProxyResolver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\WorkerCommandString;

/** Rebuild the exact existing local EIS/OCR/fake-Gemini workflow in real Horizon children. */
final class PipelineHorizonRuntime
{
    public static function boot(string $path): void
    {
        $root = realpath(dirname($path));
        if (getenv('PROCESS_CORE_TEST') !== '1' || ! app()->environment('testing') || $root === false
            || ! str_starts_with($root, '/var/www/html/storage/app/testing/') || basename($path) !== 'pipeline.json' || is_link($path)) {
            throw new \LogicException('Pipeline Horizon fixture requires isolated test storage.');
        }
        $spec = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        config($spec['config']);
        foreach (array_keys($spec['config']['filesystems.disks']) as $disk) {
            Storage::forgetDisk($disk);
        }
        $policy = new UrlPolicy(array_map(CanonicalOrigin::fromUrl(...), $spec['origins']), ['fixture.test' => ['127.0.0.1']]);
        app()->instance(UrlPolicy::class, $policy);
        app()->instance(PlatformRegistry::class, new PlatformRegistry(config()));
        app()->instance(EisCardRoutes::class, new EisCardRoutes($spec['card_routes'], ['Электронный аукцион' => 'ea20']));
        app()->instance(DocumentUrlPolicy::class, new class($spec['document_origin']) implements DocumentUrlPolicy
        {
            public function __construct(private readonly string $origin) {}

            public function assertDocumentUrl(string $url): void
            {
                if (! str_starts_with($url, $this->origin.'/')) {
                    throw new \InvalidArgumentException('Untrusted fixture document origin.');
                }
            }
        });
        app()->instance(RuntimeDownloadHandler::class, app()->makeWith(RuntimeDownloadHandler::class, ['urls' => $policy]));
        app()->bind(AcquisitionHandler::class, static fn ($app, array $params): AcquisitionHandler => new AcquisitionHandler(
            $params['stage'], $app->make(PlatformRegistry::class), $app->make(AcquisitionValidation::class), $app->make(ProxyResolver::class), $policy,
        ));
        $connection = unserialize(base64_decode($spec['provider']), ['allowed_classes' => true]);
        if (! $connection instanceof AnalysisProviderConnection) {
            throw new \LogicException('Invalid isolated provider fixture.');
        }
        $connections = new AnalysisProviderConnections;
        $connections->register($connection, preferred: true);
        app()->instance(AnalysisProviderConnections::class, $connections);
        app(ReceiptPolicies::class)->register('fixture-provider', new GeminiAssetReceiptPolicy);
        app()->forgetInstance(StageRegistry::class);
        app(StageRegistry::class);
        SupervisorCommandString::$command = 'exec @php tests/Support/ProcessCore/artisan.php horizon:supervisor';
        WorkerCommandString::$command = 'exec @php tests/Support/ProcessCore/artisan.php horizon:work';
        $supervisors = [];
        foreach (array_keys(config('horizon.defaults')) as $name) {
            $supervisors[$name] = ['maxProcesses' => $name === 'supervisor-1' ? 0 : 1, 'minProcesses' => 1, 'balance' => 'simple', 'sleep' => 1];
        }
        config(['horizon.environments.testing' => $supervisors]);
        Queue::before(static function ($event) use ($root): void {
            $job = unserialize($event->job->payload()['data']['command'] ?? '', ['allowed_classes' => [ExecuteWorkJob::class]]);
            if ($job instanceof ExecuteWorkJob) {
                file_put_contents($root.'/horizon-executions.jsonl', json_encode(['work' => $job->workId, 'pid' => getmypid()], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
            }
        });
    }
}
