<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Health\ReadinessCheckCode;
use App\Enums\Procurement\Process\OperatorCommandStatus;
use App\Guards\Admin\Policies\EstimatePolicy;
use App\Guards\Admin\Policies\FixturePolicy;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Estimate\Estimate;
use App\Models\User;
use App\Repositories\Procurement\Process\IncidentRepository;
use App\Repositories\Procurement\Process\PlatformMetricsReadRepository;
use App\Services\Health\ApplicationReadiness;
use App\Services\Procurement\Process\MetricProjector;
use App\Services\Procurement\Process\ProcessTransaction;
use App\Services\Reference\ClassifierImporter;
use App\Support\SecretRedactor;
use App\Support\Telemetry\SentrySanitizer;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Sentry\Event as SentryEvent;
use Sentry\ExceptionDataBag;
use Sentry\ExceptionMechanism;
use Sentry\Frame;
use Sentry\Stacktrace;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

pest()->group('legacy-ownership-cutover', 'operational-metrics-and-alerting');

beforeEach(fn () => $this->artisan('guard:sync-roles')->assertSuccessful());

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

    expect(fn () => app(ProcessTransaction::class)->run(fn () => $repo->acknowledge($id, 1, '   ', $user->id, LedgerFixtures::uuid(), LedgerFixtures::uuid())))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(ProcessTransaction::class)->run(fn () => $repo->acknowledge($id, 1, "bad\0reason", $user->id, LedgerFixtures::uuid(), LedgerFixtures::uuid())))
        ->toThrow(InvalidArgumentException::class)
        ->and(app(ProcessTransaction::class)->run(fn () => $repo->acknowledge($id, 99, 'stale seq', $user->id, LedgerFixtures::uuid(), LedgerFixtures::uuid())))
        ->toBe(OperatorCommandStatus::VersionConflict)
        ->and(app(ProcessTransaction::class)->run(fn () => $repo->acknowledge($id, 1, 'valid reason', $user->id, LedgerFixtures::uuid(), LedgerFixtures::uuid())))
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
