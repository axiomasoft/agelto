<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Dto\Procurement\Platform\Identity\PlatformCode;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Platforms\Eis\EisDefinition;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Platforms\Contract\ContractRuntime;

pest()->group('eis-access-policy');

test('policy отвергает неверный тип ноль overflow timezone и unknown key с config path', function (): void {
    foreach ([
        ['block_size', '500'], ['block_size', 0], ['block_size', 1001], ['block_size', null],
        ['source_timezone', 'Mars/Olympus'], ['source_timezone', 3], ['unknown', 1],
        ['endpoints.listing.profile.total_seconds', 0], ['endpoints.listing.profile.total_seconds', '90'],
        ['endpoints.listing.profile.unknown', true],
        ['okpd2.children_endpoint', ''], ['okpd2.root_external_id', 42],
        ['okpd2.root_level', 0], ['okpd2.max_requests', '20'],
        ['documents_allowed_hosts', []], ['documents_allowed_hosts', [42]],
    ] as [$key, $value]) {
        $config = new Repository(config()->all());
        $config->set('platforms.eis.'.$key, $value);
        $path = str_starts_with($key, 'endpoints.') ? 'platforms.eis.endpoints.listing.profile'
            : ($key === 'unknown' ? 'platforms.eis' : 'platforms.eis.'.$key);
        expect(fn () => new EisPolicy($config))->toThrow(InvalidPlatformDefinition::class, $path);
    }
});

test('valid policy неизменна и Definition использует тот же typed endpoint catalog', function (): void {
    foreach ([1, 500, 1000] as $size) {
        $config = new Repository(config()->all());
        $config->set('platforms.eis.block_size', $size);
        $provider = new EisDefinition($config);
        $policy = $provider->policy;
        expect($policy->blockSize)->toBe($size)
            ->and($policy->sourceTimezone->getName())->toBe('Europe/Moscow')
            ->and($provider->definition()->endpoints)->toBe($policy->endpoints)
            ->and($policy->endpoints->get('listing')->profile->totalSeconds)->toBe(90)
            ->and($policy->endpoints->get('document')->profile->totalSeconds)->toBe(240);
        $config->set('platforms.eis.block_size', 0);
        expect($policy->blockSize)->toBe($size);
    }
});

test('новый definition не читает global config а policy имеет только config constructor dependency', function (): void {
    $source = file_get_contents(app_path('Services/Platforms/Eis/EisDefinition.php'));
    expect(preg_match('/\\bconfig\\s*\\(|Config::/', $source))->toBe(0)
        ->and((new ReflectionClass(EisPolicy::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(EisPolicy::class))->getConstructor()->getParameters()[0]->getType()->getName())
        ->toBe(Illuminate\Contracts\Config\Repository::class);
});

test('boot проверяет полный timeout wait reserve и соответствующую lane без I/O', function (
    string $endpoint, int $total, string $stage, int $slice, int $wait, int $reserve, bool $fits,
): void {
    $config = new Repository(config()->all());
    $config->set('platforms.eis.endpoints.'.$endpoint.'.profile.total_seconds', $total);
    $config->set('process.stages.'.$stage.'.slice', $slice);
    $config->set('outbound.scheduling.max_inline_wait_seconds', $wait);
    $config->set('process.execution.finalization_reserve_seconds', $reserve);
    DB::shouldReceive('connection')->never();
    Http::fake();
    Http::preventStrayRequests();

    if ($fits) {
        $definition = (new PlatformRegistry($config))->get(new PlatformCode('eis'));
        // Admission must preserve the full configured timeout, including at the exact boundary.
        expect($definition->endpoints->get($endpoint)->profile->totalSeconds)->toBe($total);
    } else {
        expect(fn () => new PlatformRegistry($config))->toThrow(InvalidPlatformDefinition::class, sprintf(
            'platforms.eis.endpoints.%s.profile.total_seconds: full timeout %d + outbound.scheduling.max_inline_wait_seconds %d + process.execution.finalization_reserve_seconds %d must fit process.stages.%s.slice %d.',
            $endpoint, $total, $wait, $reserve, $stage, $slice,
        ));
    }
    Http::assertNothingSent();
})->with([
    'timeout equals whole listing slice' => ['listing', 240, 'listing', 240, 20, 15, false],
    'listing exact boundary' => ['listing', 205, 'listing', 240, 20, 15, true],
    'listing one second over boundary' => ['listing', 206, 'listing', 240, 20, 15, false],
    'card uses short lane boundary' => ['card', 55, 'detail', 90, 20, 15, true],
    'card cannot borrow listing slice' => ['card', 56, 'detail', 90, 20, 15, false],
    'document uses download boundary' => ['document', 265, 'download', 300, 20, 15, true],
    'document over download boundary' => ['document', 266, 'download', 300, 20, 15, false],
    'configured shorter wait fits' => ['listing', 206, 'listing', 240, 19, 15, true],
    'configured larger reserve fails' => ['listing', 205, 'listing', 240, 20, 16, false],
    'configured larger reserve boundary' => ['listing', 204, 'listing', 240, 20, 16, true],
    'configured shorter slice fails' => ['listing', 205, 'listing', 239, 20, 15, false],
    'configured longer slice boundary' => ['listing', 206, 'listing', 241, 20, 15, true],
    'large positive integer cannot overflow fit check' => ['listing', PHP_INT_MAX, 'listing', 240, 20, 15, false],
]);

test('boot отвергает неполные и неверно типизированные timing inputs без fallback и I/O', function (string $path, mixed $value): void {
    $config = new Repository(config()->all());
    $config->set($path, $value);
    DB::shouldReceive('connection')->never();
    Http::fake();
    Http::preventStrayRequests();
    expect(fn () => new PlatformRegistry($config))->toThrow(InvalidArgumentException::class, $path);
    Http::assertNothingSent();
})->with([
    ['process.stages.listing.slice', null],
    ['process.stages.listing.slice', '240'],
    ['outbound.scheduling.max_inline_wait_seconds', null],
    ['outbound.scheduling.max_inline_wait_seconds', '20'],
    ['process.execution.finalization_reserve_seconds', null],
    ['process.execution.finalization_reserve_seconds', '15'],
    ['process.execution.finalization_reserve_seconds', 14],
]);

test('runtime по-прежнему требует полный timeout плюс reserve', function (): void {
    $context = ContractRuntime::context();
    expect($context->canStart(Duration::seconds(240)))->toBeFalse()
        ->and($context->canStart(Duration::seconds(205 + 20)))->toBeTrue()
        ->and($context->canStart(Duration::seconds(206 + 20)))->toBeFalse();
});
