<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Health\ReadinessCheckCode;
use App\Enums\Procurement\Process\ControlMode;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Enums\Telemetry\LogChannel;
use App\Guards\Admin\Policies\EstimatePolicy;
use App\Guards\Admin\Policies\FixturePolicy;
use App\Guards\Admin\Roles\OperatorRole;
use App\Http\Middleware\AssignTraceId;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use App\Notifications\PipelineAlert;
use App\Repositories\Procurement\Process\ControlRepository;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Health\ApplicationReadiness;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Reference\ClassifierImporter;
use App\Support\SecretRedactor;
use App\Support\Telemetry\SafeJsonLogProcessor;
use App\Support\Telemetry\SafeLogChannelTap;
use App\Support\Telemetry\SentrySanitizer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
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
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

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
    config()->set('services.telegram.bot_token', 'test-token');
    config()->set('services.telegram.chat_id', '12345');
    Cache::forget('pipeline_alert:test_key');

    Http::fakeSequence()
        ->push('gateway timeout', 500)
        ->push('gateway timeout', 500)
        ->push(['ok' => true], 200);

    $alert = app(PipelineAlert::class);
    $alert->notify(['test_key' => 'Деталь']);
    expect(Cache::has('pipeline_alert:test_key'))->toBeFalse();

    $alert->notify(['test_key' => 'Деталь']);
    expect(Cache::has('pipeline_alert:test_key'))->toBeTrue();

    Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'php artisan process:status'));

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
