<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\BlobReference;
use App\Dto\Procurement\Document\DocumentHead;
use App\Dto\Procurement\Document\DocumentRevision;
use App\Dto\Procurement\Document\SourceDocumentMetadata;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Health\ReadinessCheckCode;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Procurement\Process\StageCode;
use App\Enums\Telemetry\LogChannel;
use App\Guards\Admin\Policies\EstimatePolicy;
use App\Guards\Admin\Policies\FixturePolicy;
use App\Guards\Admin\Roles\OperatorRole;
use App\Http\Middleware\AssignTraceId;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use App\Notifications\PipelineAlert;
use App\Repositories\Procurement\Document\DocumentVersionRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Health\ApplicationReadiness;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessDiagnosticsInspector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Proxyma\TelegramOutbound;
use App\Services\Reference\ClassifierImporter;
use App\Support\SecretRedactor;
use App\Support\Telemetry\SafeJsonLogProcessor;
use App\Support\Telemetry\SafeLogChannelTap;
use App\Support\Telemetry\SentrySanitizer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Sentry\Event as SentryEvent;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Stacktrace;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ProcessCore\DocumentStorageFixtures;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\HistoricalNetworkFixtures;
use Tests\Support\ProcessCore\Http\PermitFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;
use Tests\Support\ProcessCore\ProxymaCallerFixtures;

pest()->group('legacy-ownership-cutover', 'operational-metrics-and-alerting');

beforeEach(fn () => $this->artisan('guard:sync-roles')->assertSuccessful());

function auditObsAcknowledge(ProcessUuid $incidentId, int $seq, string $reason, int $userId): OperatorCommandStatus
{
    return app(ProcessTransaction::class)->run(fn () => app(IncidentRepository::class)->acknowledge(
        $incidentId,
        $seq,
        $reason,
        $userId,
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ));
}

test('IncidentRepository::acknowledge проверяет оптимистичную версию expectedTransitionSeq и валидирует reason', function (): void {
    $user = User::factory()->create();
    $id = LedgerFixtures::uuid();
    $repo = app(IncidentRepository::class);
    app(ProcessTransaction::class)->run(fn () => $repo->open(
        LedgerFixtures::hash('incident-seq-audit'),
        'acquisition',
        'audit_test',
        [],
        $id,
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ));

    expect(fn () => auditObsAcknowledge($id, 1, '   ', $user->id))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => auditObsAcknowledge($id, 1, "bad\0reason", $user->id))
        ->toThrow(InvalidArgumentException::class)
        ->and(auditObsAcknowledge($id, 99, 'stale seq', $user->id))
        ->toBe(OperatorCommandStatus::VersionConflict)
        ->and(auditObsAcknowledge($id, 1, 'valid reason', $user->id))
        ->toBe(OperatorCommandStatus::Applied);
});

test('SecretRedactor редактирует signature/sig/cursor, а SentrySanitizer очищает vars фреймов стектрейса и data механизма исключения', function (): void {
    $redacted = SecretRedactor::text('https://example.test/download?signature=secret123&sig=abc999&cursor=opaqueToken42');
    expect($redacted)->not->toContain('secret123', 'abc999', 'opaqueToken42')
        ->and($redacted)->toContain('[redacted]');

    $event = SentryEvent::createEvent();
    $frame = new Frame(
        functionName: 'connect',
        file: '/app/Service.php',
        line: 10,
        vars: ['api_key' => 'super-secret-key', 'url' => 'https://user:pass123@example.test/api?token=xyz987'],
    );
    $bag = new ExceptionDataBag(
        new RuntimeException('Failed with token=mySecretToken'),
        new Stacktrace([$frame]),
        new ExceptionMechanism(type: 'generic', handled: true, data: ['authorization' => 'Bearer secretBearerToken']),
    );
    $event->setExceptions([$bag]);

    $sanitized = SentrySanitizer::beforeSend($event);
    assert($sanitized instanceof SentryEvent);
    $sanitizedBag = $sanitized->getExceptions()[0];
    $vars = $sanitizedBag->getStacktrace()?->getFrames()[0]->getVars() ?? [];
    $mechData = $sanitizedBag->getMechanism()?->getData() ?? [];

    expect($vars['api_key'])->toBe('[redacted]')
        ->and($vars['url'])->not->toContain('pass123', 'xyz987')
        ->and($mechData['authorization'])->toBe('[redacted]');
});

test('ClassifierImporter снимает UTF-8 BOM, требует колонку code и отвергает невалидный JSON attributes', function (): void {
    $importer = app(ClassifierImporter::class);
    $tmp = tempnam(sys_get_temp_dir(), 'clf_audit_');
    assert(is_string($tmp));

    try {
        $bom = "\xEF\xBB\xBF";
        file_put_contents($tmp, $bom."code,name,parent_code,level\n41.20,Строительство,,2\n");
        $rows = iterator_to_array($importer->rows($tmp));

        expect($rows)->toHaveCount(1)
            ->and($rows[0]['code'])->toBe('41.20');

        file_put_contents($tmp, "name,level\nТест,1\n");
        expect(fn () => iterator_to_array($importer->rows($tmp)))->toThrow(RuntimeException::class);
    } finally {
        @unlink($tmp);
    }
});

test('EstimatePolicy и FixturePolicy явно закрывают деструктивные Filament-действия для пользователя без прав', function (): void {
    $user = User::factory()->create();
    $estimate = new Estimate;
    $fixture = new Fixture;
    $estimatePolicy = new EstimatePolicy;
    $fixturePolicy = new FixturePolicy;

    expect($estimatePolicy->delete($user, $estimate))->toBeFalse()
        ->and($estimatePolicy->deleteAny($user))->toBeFalse()
        ->and($estimatePolicy->forceDelete($user, $estimate))->toBeFalse()
        ->and($fixturePolicy->delete($user, $fixture))->toBeFalse()
        ->and($fixturePolicy->deleteAny($user))->toBeFalse()
        ->and($fixturePolicy->forceDelete($user, $fixture))->toBeFalse();
});

test('PlatformMetricsReadRepository::read учитывает свежие и сверяющие прогоны и не раздувает число партиций при дублирующих связях run_work', function (): void {
    ListingFixtures::bind();
    app(MetricProjector::class)->ensureStates();

    $snapshot = app(PlatformMetricsReadRepository::class)->read(new PlatformCode('eis'));
    expect($snapshot->platform->value)->toBe('eis')
        ->and($snapshot->closedPartitions)->toBe(0);
});

test('ApplicationReadiness записывает стартовый heartbeat при старте schedule:work и поддерживает фильтрацию проверок через --only', function (): void {
    Cache::forget(ApplicationReadiness::SchedulerHeartbeatKey);
    expect(Cache::get(ApplicationReadiness::SchedulerHeartbeatKey))->toBeNull();

    Event::dispatch(new CommandStarting('schedule:work', new ArrayInput([]), new NullOutput));
    expect(Cache::get(ApplicationReadiness::SchedulerHeartbeatKey))->toBeString();

    $onlyScheduler = app(ApplicationReadiness::class)->check([ReadinessCheckCode::Scheduler]);
    expect($onlyScheduler)->toHaveCount(1)
        ->and($onlyScheduler[0]->code)->toBe(ReadinessCheckCode::Scheduler)
        ->and($onlyScheduler[0]->passed)->toBeTrue();

    $this->artisan('app:readiness', ['--only' => 'scheduler', '--format' => 'json'])
        ->assertExitCode(0);

    $this->artisan('app:readiness', ['--only' => 'unknown_check'])
        ->assertExitCode(2);
});

test('жизненный цикл trace_id в CLI и HTTP генерирует 32-символьный hex, восстанавливает стек при вложенных командах и нормализует UUID в WorkFinalizer', function (): void {
    Context::forget(['trace_id', 'command']);

    Event::dispatch(new CommandStarting('process:status', new ArrayInput([]), new NullOutput));
    $outerTrace = Context::get('trace_id');
    expect($outerTrace)->toBeString()->toMatch('/^[0-9a-f]{32}$/D')
        ->and(Context::get('command'))->toBe('process:status');

    Event::dispatch(new CommandStarting('app:readiness', new ArrayInput([]), new NullOutput));
    $innerTrace = Context::get('trace_id');
    expect($innerTrace)->toBeString()->toMatch('/^[0-9a-f]{32}$/D')
        ->not->toBe($outerTrace)
        ->and(Context::get('command'))->toBe('app:readiness');

    Event::dispatch(new CommandFinished('app:readiness', new ArrayInput([]), new NullOutput, 0));
    expect(Context::get('trace_id'))->toBe($outerTrace)
        ->and(Context::get('command'))->toBe('process:status');

    Event::dispatch(new CommandFinished('process:status', new ArrayInput([]), new NullOutput, 0));
    expect(Context::get('trace_id'))->toBeNull()
        ->and(Context::get('command'))->toBeNull();

    $middleware = new AssignTraceId;
    $request = Request::create('/up', 'GET');
    $middleware->handle($request, fn () => new Response('ok'));
    expect(Context::get('trace_id'))->toBeString()->toMatch('/^[0-9a-f]{32}$/D');

    $finalizer = app(WorkFinalizer::class);
    $method = new ReflectionMethod($finalizer, 'normalizeTraceId');
    expect($method->invoke($finalizer, '01234567-89AB-CDEF-0123-456789ABCDEF'))
        ->toBe('0123456789abcdef0123456789abcdef')
        ->and($method->invoke($finalizer, 'not-a-valid-hex-trace'))
        ->toBeNull();
});

test('SafeLogChannelTap, SecretRedactor, Horizon gate и операторские репозитории редактируют секреты и блокируют неавторизованный доступ', function (): void {
    foreach ([
        'single', 'daily', 'slack', 'papertrail', 'stderr', 'json_file', 'json_stderr', 'syslog', 'errorlog',
        'process', 'network', 'platforms', 'procurement', 'storage', 'alerts', 'system_warnings', 'system_errors',
    ] as $channel) {
        expect(config("logging.channels.{$channel}.tap"))->toContain(SafeLogChannelTap::class);
    }

    $logger = new Logger('test');
    $tap = app(SafeLogChannelTap::class);
    $tap($logger);
    $tap($logger);
    $processors = $logger->getProcessors();
    expect($processors)->toHaveCount(2)
        ->and($processors[0])->toBeInstanceOf(SafeJsonLogProcessor::class)
        ->and($processors[1])->toBeInstanceOf(PsrLogMessageProcessor::class);

    $raw = "amqp://user:pass123@broker.internal:5672/vhost\n"
        ."-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQEA\n-----END RSA PRIVATE KEY-----\n"
        ."Proxy-Authorization: Basic dXNlcjpwYXNz\n"
        ."X-Goog-Api-Key: AIzaSySecretKeyValue\n"
        ."Cookie: laravel_session=abc123xyz\n"
        .'client_secret=my_top_secret refresh_token=ref_tok_999';
    $redacted = SecretRedactor::text($raw);
    expect($redacted)->toContain('amqp://[redacted]@broker.internal:5672/vhost')
        ->not->toContain('pass123')
        ->not->toContain('MIIEpAIBAAKCAQEA')
        ->not->toContain('dXNlcjpwYXNz')
        ->not->toContain('AIzaSySecretKeyValue')
        ->not->toContain('abc123xyz')
        ->not->toContain('my_top_secret')
        ->not->toContain('ref_tok_999');

    $ctx = SecretRedactor::context([
        'private_key' => 'secret-pk',
        'proxy_auth' => 'secret-proxy',
        'signature' => 'secret-sig',
        'safe_field' => 'ok',
    ]);
    expect($ctx['private_key'])->toBe('[redacted]')
        ->and($ctx['proxy_auth'])->toBe('[redacted]')
        ->and($ctx['signature'])->toBe('[redacted]')
        ->and($ctx['safe_field'])->toBe('ok');

    $this->artisan('guard:sync-roles')->assertSuccessful();
    $guestAllowed = Gate::forUser(null)->allows('viewHorizon');
    $regularUser = User::factory()->create();
    $operatorUser = User::factory()->create();
    $operatorUser->assignRole(OperatorRole::class);
    expect($guestAllowed)->toBeFalse()
        ->and(Gate::forUser($regularUser)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($operatorUser)->allows('viewHorizon'))->toBeTrue();

    ListingFixtures::bind();
    $controlRepo = app(ControlRepository::class);
    $expectedVersion = (int) DB::table('prc_control_scopes')->where('scope_key', 'system')->value('control_version');
    app(ProcessTransaction::class)->run(fn () => $controlRepo->setMode(
        'system',
        ControlMode::Paused,
        $operatorUser->id,
        'Пауза из-за утечки Basic dXNlcjpwYXNz в логах',
        $expectedVersion,
        LedgerFixtures::uuid(),
        LedgerFixtures::uuid(),
    ));
    $storedReason = DB::table('prc_control_scopes')
        ->where('scope_key', 'system')
        ->value('change_reason');
    expect($storedReason)->toContain('[redacted]')
        ->not->toContain('dXNlcjpwYXNz');
});

test('PipelineAlert ставит дедупликационный ключ в кэш только после успешной доставки в Telegram и указывает process:status', function (): void {
    PermitFixtures::clean();
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.chat_id', '12345');
    Cache::forget('pipeline_alert:test_key');

    $wire = new WireFixture;
    try {
        new PermitFixtures($wire);
        $profile = TransportFixtures::profile([
            'code' => 'notification.telegram', 'attempts' => 1, 'distinct_routes' => 1, 'delays_ms' => [], 'redirects' => 0]);
        app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(
            new ProviderKey('telegram'), [new SourceTargetDefinition(
                'provider:telegram:notification', 'notification', $wire->url('/')->origin, [$profile])]));
        HistoricalNetworkFixtures::synchronize();
        ProxymaCallerFixtures::install($wire);
        config()->set('services.telegram.api_url', $wire->url('/')->origin->value());
        app()->instance(TelegramOutbound::class,
            new TelegramOutbound($wire->policy()));
        $path = '/bottest-token/sendMessage';
        $wire->respond($path, 'gateway timeout', 500, method: 'POST');
        $wire->respond($path, '{"ok":true}', method: 'POST');
        $alert = app(PipelineAlert::class);
        $alert->notify(['test_key' => 'Деталь']);
        expect(Cache::has('pipeline_alert:test_key'))->toBeFalse()->and($wire->receipts())->toHaveCount(1);
        $alert->notify(['test_key' => 'Деталь']);
        expect(Cache::has('pipeline_alert:test_key'))->toBeTrue()->and($wire->receipts())->toHaveCount(2);
        $payload = json_encode(['chat_id' => '12345', 'text' => implode("\n", [
            '⚠️ <b>[AGELTO ['.mb_strtoupper(config('app.env')).']] Проблемы пайплайна</b>', '', '• Деталь', '',
            'Проверьте Horizon или запустите <code>php artisan process:status</code> / <code>php artisan pipeline:status</code>',
        ]), 'parse_mode' => 'HTML'], JSON_THROW_ON_ERROR);
        expect($wire->receipts()[1]['body_sha256'])->toBe(hash('sha256', $payload));
        $alert->notify(['test_key' => 'Деталь']);
        expect($wire->receipts())->toHaveCount(2);
    } finally {
        $wire->stop();
        PermitFixtures::clean();
    }

    $logRoot = (string) config('testing.log_root');
    LogChannel::Platforms->warning('platform-dom-drift-marker', ['tab' => 'common-info']);
    LogChannel::Process->error('process-incident-marker', ['daemon' => 'relay']);

    $readGlob = static function (string $pattern): string {
        $files = glob($pattern) ?: [];

        return implode("\n", array_map(static fn (string $f): string => (string) file_get_contents($f), $files));
    };

    expect($readGlob("{$logRoot}/platforms*.log"))->toContain('platform-dom-drift-marker')
        ->and($readGlob("{$logRoot}/system-warnings*.log"))->toContain('platform-dom-drift-marker')
        ->and($readGlob("{$logRoot}/process*.log"))->toContain('process-incident-marker')
        ->and($readGlob("{$logRoot}/system-errors*.log"))->toContain('process-incident-marker')
        ->and($readGlob("{$logRoot}/laravel*.log"))->not->toContain('platform-dom-drift-marker')
        ->and($readGlob("{$logRoot}/laravel*.log"))->not->toContain('process-incident-marker');
});

test('ApplicationReadiness пропускает delete в finally при истечении дедлайна во время записи в хранилище и до подключения к БД', function (): void {
    config()->set('readiness.storage.disk', 's3');

    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')
        ->once()
        ->andReturnUsing(static function (): bool {
            usleep(25_000);

            return true;
        });
    $disk->shouldNotReceive('get');
    $disk->shouldNotReceive('delete');

    $filesystemFactory = Mockery::mock(FilesystemFactory::class);
    $filesystemFactory->shouldReceive('disk')->with('s3')->andReturn($disk);
    $this->instance(FilesystemFactory::class, $filesystemFactory);

    $readiness = app(ApplicationReadiness::class);
    $results = $readiness->check([ReadinessCheckCode::Storage], hrtime(true) + 5_000_000);

    expect($results)->toHaveCount(1)
        ->and($results[0]->code)->toBe(ReadinessCheckCode::Storage)
        ->and($results[0]->passed)->toBeFalse()
        ->and($results[0]->message)->toBe('unavailable');

    $storageMethod = new ReflectionMethod($readiness, 'storage');
    $expiredStorage = $storageMethod->invoke($readiness, hrtime(true) - 1_000_000);
    expect($expiredStorage->passed)->toBeFalse()
        ->and($expiredStorage->message)->toBe('unavailable');

    $dbResolver = Mockery::mock(ConnectionResolverInterface::class);
    $dbResolver->shouldNotReceive('connection');
    $this->instance(ConnectionResolverInterface::class, $dbResolver);
    $readinessWithMockDb = app(ApplicationReadiness::class);
    $pgMethod = new ReflectionMethod($readinessWithMockDb, 'runWithPgTimeouts');
    expect(fn () => $pgMethod->invoke($readinessWithMockDb, hrtime(true) - 1_000_000, static function (): void {}))
        ->toThrow(RuntimeException::class, 'Database readiness deadline exceeded');
});

test('ApplicationReadiness::checkIsolated жёстко ограничивает зависший процесс пробы по времени, зачищает группу процессов и поддерживает --isolated', function (): void {
    $pidFile = tempnam(sys_get_temp_dir(), 'readiness_iso_');
    assert(is_string($pidFile));

    try {
        $script = sprintf(
            'if (function_exists("pcntl_signal")) { pcntl_signal(SIGTERM, SIG_IGN); } '
            .'$child = proc_open([PHP_BINARY, "-r", "if (function_exists(\"pcntl_signal\")) { pcntl_signal(SIGTERM, SIG_IGN); } sleep(30);"], [], $pipes); '
            .'$st = proc_get_status($child); '
            .'file_put_contents(%s, getmypid()."\n".$st["pid"]); '
            .'sleep(30);',
            var_export($pidFile, true),
        );

        $startedNs = hrtime(true);
        $results = app(ApplicationReadiness::class)->checkIsolated(
            only: [ReadinessCheckCode::Database, ReadinessCheckCode::Storage],
            timeoutSeconds: 0.3,
            commandOverride: [PHP_BINARY, '-r', $script],
        );
        $elapsedSeconds = (hrtime(true) - $startedNs) / 1_000_000_000;

        $pids = array_values(array_filter(
            array_map('intval', explode("\n", trim((string) file_get_contents($pidFile)))),
            static fn (int $pid): bool => $pid > 0,
        ));

        expect($elapsedSeconds)->toBeLessThan(2.0)
            ->and($results)->toHaveCount(2)
            ->and($results[0]->toArray())->toBe(['code' => 'database', 'passed' => false, 'message' => 'unavailable'])
            ->and($results[1]->toArray())->toBe(['code' => 'storage', 'passed' => false, 'message' => 'unavailable'])
            ->and($pids)->toHaveCount(2);

        foreach ($pids as $pid) {
            $status = @file_get_contents("/proc/{$pid}/status");
            $isDeadOrZombie = ! is_dir("/proc/{$pid}") || (is_string($status) && preg_match('/^State:\s+Z\b/m', $status) === 1);
            expect($isDeadOrZombie)->toBeTrue();
        }
        expect(is_dir("/proc/{$pids[0]}"))->toBeFalse();
    } finally {
        @unlink($pidFile);
    }

    $this->artisan('app:readiness', [
        '--only' => 'database',
        '--format' => 'json',
        '--isolated' => true,
    ])->assertExitCode(0);
});

test('ProcessDiagnosticsInspector проверяет покрытие очередей и прогресс всех 8 стадий по connection+queue и часам БД', function (): void {
    config()->set('process.diagnostic_thresholds.analysis_stuck_minutes', 30);

    ExecutionFixtures::listingWork();
    ExecutionFixtures::detailWork();
    ExecutionFixtures::statusWork();

    $document = Document::factory()->create();
    $downloadWorkId = LedgerFixtures::uuid();
    DB::table('prc_process_work')->insert([
        'id' => $downloadWorkId->value,
        'stage' => StageCode::Download->value,
        'work_key' => DB::raw("decode('".LedgerFixtures::hash('download-paused:'.$downloadWorkId->value)->hex."', 'hex')"),
        'work_identity' => '{}',
        'work_identity_schema_version' => 1,
        'input_revision' => 1,
        'input_hash' => DB::raw("decode('".LedgerFixtures::hash('download-input')->hex."', 'hex')"),
        'input_payload' => '{}',
        'input_schema_version' => 1,
        'state' => 'blocked',
        'reason_code' => 'paused',
        'cohort_key' => 'manual',
        'document_id' => $document->id,
        'created_at' => DB::raw('clock_timestamp()'),
        'updated_at' => DB::raw('clock_timestamp()'),
    ]);

    $domain = DocumentStorageFixtures::domain();
    $blobId = LedgerFixtures::uuid();
    $artifactHash = LedgerFixtures::hash('screening-doc-version');
    $blobRef = new BlobReference($blobId, $domain, $artifactHash, 'local', 'objects/'.$artifactHash->hex, 16);
    $revision = app(ProcessTransaction::class)->run(function () use ($document, $domain, $blobRef): DocumentRevision {
        app(StorageCapacityRepository::class)->lockDomains([$domain]);
        DB::insert("INSERT INTO prc_document_blobs(id,storage_domain_id,content_hash,disk,object_key,size_bytes,verified_at,deletion_generation) VALUES (?,?,decode(?,'hex'),?,?,?,clock_timestamp(),0)", [
            $blobRef->id->value,
            $domain->value,
            $blobRef->hash->hex,
            $blobRef->disk,
            $blobRef->objectKey,
            $blobRef->sizeBytes,
        ]);

        return app(DocumentVersionRepository::class)->observeBlobLocked(
            new DocumentHead($document->id, 1, null),
            $blobRef,
            new SourceDocumentMetadata('fixture.pdf', 'application/pdf'),
        );
    });
    $screeningWorkId = new WorkId(LedgerFixtures::uuid());
    DB::table('prc_process_work')->insert([
        'id' => $screeningWorkId->value->value,
        'stage' => StageCode::Screening->value,
        'work_key' => DB::raw("decode('".LedgerFixtures::hash('screening-stalled:'.$screeningWorkId->value->value)->hex."', 'hex')"),
        'work_identity' => '{}',
        'work_identity_schema_version' => 1,
        'input_revision' => 1,
        'input_hash' => DB::raw("decode('".LedgerFixtures::hash('screening-input')->hex."', 'hex')"),
        'input_payload' => '{}',
        'input_schema_version' => 1,
        'state' => 'ready',
        'cohort_key' => 'manual',
        'document_version_id' => $revision->id,
        'created_at' => DB::raw("clock_timestamp() - interval '45 minutes'"),
        'updated_at' => DB::raw("clock_timestamp() - interval '45 minutes'"),
    ]);

    $supervisors = Mockery::mock(SupervisorRepository::class);
    $supervisors->shouldReceive('all')->andReturn([
        (object) [
            'name' => 'master:supervisor-process-listing',
            'status' => 'running',
            'options' => ['connection' => 'process_long', 'queue' => 'process-listing'],
            'processes' => ['process_long:process-listing' => 3],
        ],
        (object) [
            'name' => 'master:supervisor-process-acquisition',
            'status' => 'running',
            'options' => ['connection' => 'process_short', 'queue' => 'process-acquisition'],
            'processes' => ['process_short:process-acquisition' => 0],
        ],
        (object) [
            'name' => 'master:supervisor-process-download',
            'status' => 'running',
            'options' => ['connection' => 'process_download', 'queue' => 'process-download'],
            'processes' => ['process_download:process-download' => 2],
        ],
        (object) [
            'name' => 'master:supervisor-process-prepare',
            'status' => 'paused',
            'options' => ['connection' => 'process_long', 'queue' => 'process-prepare'],
            'processes' => ['process_long:process-prepare' => 2],
        ],
        (object) [
            'name' => 'master:supervisor-process-ai',
            'status' => 'running',
            'options' => ['connection' => 'process_ai', 'queue' => 'process-ai'],
            'processes' => ['process_ai:process-ai' => 4],
        ],
        (object) [
            'name' => 'master:supervisor-process-publication',
            'status' => 'running',
            'options' => ['connection' => 'process_short', 'queue' => 'process-publication'],
            'processes' => ['process_short:process-publication' => 1],
        ],
    ]);
    $this->instance(SupervisorRepository::class, $supervisors);

    $inspector = app(ProcessDiagnosticsInspector::class);
    $report = $inspector->inspectStageQueues();

    expect(array_keys($report))->toBe([
        'listing', 'detail', 'status_refresh', 'download', 'prepare', 'screening', 'extraction', 'publication',
    ])
        ->and($report['listing']['status'])->toBe('active_backlog')
        ->and($report['listing']['has_active_consumer'])->toBeTrue()
        ->and($report['listing']['eligible_backlog'])->toBe(1)
        ->and($report['detail']['status'])->toBe('unconsumed_queue')
        ->and($report['detail']['has_active_consumer'])->toBeFalse()
        ->and($report['detail']['connection'])->toBe('process_short')
        ->and($report['detail']['queue'])->toBe('process-acquisition')
        ->and($report['status_refresh']['status'])->toBe('unconsumed_queue')
        ->and($report['status_refresh']['has_active_consumer'])->toBeFalse()
        ->and($report['download']['status'])->toBe('paused')
        ->and($report['download']['paused_count'])->toBe(1)
        ->and($report['download']['eligible_backlog'])->toBe(0)
        ->and($report['prepare']['status'])->toBe('no_work')
        ->and($report['prepare']['has_active_consumer'])->toBeFalse()
        ->and($report['screening']['status'])->toBe('stalled_progress')
        ->and($report['screening']['oldest_eligible_age_seconds'])->toBeGreaterThanOrEqual(2700)
        ->and($report['extraction']['status'])->toBe('no_work')
        ->and($report['extraction']['has_active_consumer'])->toBeTrue()
        ->and($report['publication']['status'])->toBe('no_work')
        ->and($report['publication']['has_active_consumer'])->toBeTrue();

    $findings = $inspector->stageQueueCoverage();
    expect($findings)
        ->toHaveKey('stage_queue_detail')
        ->toHaveKey('stage_queue_status_refresh')
        ->toHaveKey('stage_queue_prepare')
        ->toHaveKey('stage_stalled_screening')
        ->not->toHaveKey('stage_queue_listing')
        ->not->toHaveKey('stage_queue_extraction')
        ->not->toHaveKey('stage_queue_publication')
        ->and($findings['stage_queue_detail'])->toContain('process_short:process-acquisition')
        ->and($findings['stage_queue_prepare'])->toContain('process_long:process-prepare')
        ->and($findings['stage_stalled_screening'])->toContain('process_ai:process-ai');

    DB::table('prc_process_transitions')->insert([
        'event_id' => LedgerFixtures::uuid()->value,
        'entity_kind' => 'work',
        'work_id' => $screeningWorkId->value->value,
        'entity_seq' => 99,
        'previous_state' => 'running',
        'new_state' => 'succeeded',
        'actor_kind' => 'worker',
        'occurred_at' => DB::raw('clock_timestamp()'),
        'schema_version' => 1,
    ]);

    $afterProgress = $inspector->inspectStageQueues();
    expect($afterProgress['screening']['status'])->toBe('active_backlog')
        ->and($inspector->stageQueueCoverage())->not->toHaveKey('stage_stalled_screening');
});

test('readme.md содержит актуальные таблицы Process Core, методы транспорта и существующие цели Makefile', function (): void {
    $readme = (string) file_get_contents(base_path('readme.md'));

    foreach ([
        'prc_work_items',
        'prc_work_executions',
        'prc_outbox_events',
        'prc_execution_leases',
        'OutboundExecutor::sendOnce',
        'make core-test-feature',
        'make prod:preflight',
    ] as $forbidden) {
        expect($readme)->not->toContain($forbidden);
    }

    expect($readme)->not->toMatch('/\bmake\s+readiness\b(?!:)/')
        ->and($readme)->toContain(
            'prc_process_work',
            'prc_process_attempts',
            'prc_process_outbox',
            'prc_operation_intents',
            'prc_process_runs',
            'prc_process_daemon_heartbeats',
            'OutboundExecutor::execute()',
            'HttpTransport::sendOnce()',
            'AnalysisProviderSendGate',
            'make readiness:check',
            'make prod:readiness',
            'make infra:preflight',
            'make core-test-up',
            'make core-test-reset',
        );

    $rootMakefile = (string) file_get_contents(base_path('Makefile'));
    $dockerMakefile = (string) file_get_contents(base_path('docker/Makefile'));
    $definedTargets = [];

    if (preg_match('/^CORE_TARGETS\s*:=\s*(.+)$/m', $rootMakefile, $coreMatch) === 1) {
        foreach (preg_split('/\s+/', trim($coreMatch[1])) ?: [] as $target) {
            if ($target !== '') {
                $definedTargets[$target] = true;
            }
        }
    }

    if (preg_match_all('/^([a-z0-9\\\\:_-]+):/m', $dockerMakefile, $dockerMatches) > 0) {
        foreach ($dockerMatches[1] as $rawTarget) {
            $definedTargets[str_replace('\\:', ':', $rawTarget)] = true;
        }
    }

    preg_match_all('/\bmake\s+([a-z0-9:_-]+)(?=\s|$|`)/m', $readme, $readmeMakeMatches);
    $referencedTargets = array_values(array_unique(array_filter(
        $readmeMakeMatches[1],
        static fn (string $t): bool => $t !== 'and' && ! str_contains($t, '<'),
    )));

    expect($referencedTargets)->not->toBeEmpty();
    foreach ($referencedTargets as $target) {
        expect($definedTargets)->toHaveKey($target);
    }
});
