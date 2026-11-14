<?php

declare(strict_types=1);

use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Platform\SourceTargetDefinitions;
use Tests\Support\ProcessCore\NetworkFixtures;

test('canonical origin объединяет IDNA default port и эквивалентные IPv6 адреса', function (): void {
    expect(CanonicalOrigin::fromUrl('HTTPS://ПРИМЕР.РФ/a')->value())->toBe('https://xn--e1afmkfd.xn--p1ai:443')
        ->and(CanonicalOrigin::fromUrl('https://example.test')->hash()->hex)->toBe(CanonicalOrigin::fromUrl('https://EXAMPLE.test.:443/a?q=x')->hash()->hex)
        ->and(CanonicalOrigin::fromUrl('https://[0:0:0:0:0:0:0:1]')->value())->toBe(CanonicalOrigin::fromUrl('https://[::1]')->value());
    expect(fn () => CanonicalOrigin::fromUrl('https://user:secret@example.test'))->toThrow(InvalidArgumentException::class);
    expect((new OriginBudgetPolicy(7, 1, 1))->emissionIntervalUs())->toBe(8_571_429);
});

test('profile JSON принимает unordered keys но не unknown keys и неверные timeout counts', function (): void {
    $data = NetworkFixtures::profile()->toArray();
    expect(RequestProfile::fromArray(array_reverse($data, true))->toArray())->toBe($data);
    foreach ([['attempts' => 6], ['distinct_routes' => 3], ['redirects' => 4], ['total_seconds' => 4], ['delays_ms' => [-1, 600]], ['delays_ms' => ['200', 600]], ['extra' => 1]] as $bad) {
        expect(fn () => RequestProfile::fromArray(array_replace($data, $bad)))->toThrow(InvalidArgumentException::class);
    }
});

test('конфликтующие defaults aliases одного origin отклоняются до записи', function (): void {
    $origin = CanonicalOrigin::fromUrl('https://example.test');
    expect(fn () => new SourceTargetDefinitions('fixture', [
        new SourceTargetDefinition('one', 'detail', $origin, [NetworkFixtures::profile()], new OriginBudgetPolicy(15, 1, 2)),
        new SourceTargetDefinition('two', 'detail', $origin, [NetworkFixtures::profile()], new OriginBudgetPolicy(30, 1, 2)),
    ]))->toThrow(InvalidArgumentException::class, 'Conflicting origin');
});

pest()->group('network-contracts');
