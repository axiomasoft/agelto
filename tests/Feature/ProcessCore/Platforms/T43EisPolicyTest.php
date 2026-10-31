<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;
use App\Services\Platforms\Eis\EisDefinition;
use Illuminate\Config\Repository;

pest()->group('t43', 'p22');

test('T43 policy отвергает неверный тип ноль overflow timezone и unknown key с config path', function (): void {
    foreach ([
        ['block_size', '500'], ['block_size', 0], ['block_size', 1001], ['block_size', null],
        ['source_timezone', 'Mars/Olympus'], ['source_timezone', 3], ['unknown', 1],
        ['endpoints.listing.profile.total_seconds', 0], ['endpoints.listing.profile.total_seconds', '90'],
        ['endpoints.listing.profile.unknown', true],
    ] as [$key, $value]) {
        $config = new Repository(config()->all());
        $config->set('platforms.eis.'.$key, $value);
        $path = str_starts_with($key, 'endpoints.') ? 'platforms.eis.endpoints.listing.profile'
            : ($key === 'unknown' ? 'platforms.eis' : 'platforms.eis.'.$key);
        expect(fn () => new EisPolicy($config))->toThrow(InvalidPlatformDefinition::class, $path);
    }
});

test('T43 valid policy неизменна и Definition использует тот же typed endpoint catalog', function (): void {
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

test('T43 новый definition не читает global config а policy имеет только config constructor dependency', function (): void {
    $source = file_get_contents(app_path('Services/Platforms/Eis/EisDefinition.php'));
    expect(preg_match('/\\bconfig\\s*\\(|Config::/', $source))->toBe(0)
        ->and((new ReflectionClass(EisPolicy::class))->isReadOnly())->toBeTrue()
        ->and((new ReflectionClass(EisPolicy::class))->getConstructor()->getParameters()[0]->getType()->getName())
        ->toBe(Illuminate\Contracts\Config\Repository::class);
});
