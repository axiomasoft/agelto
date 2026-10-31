<?php

declare(strict_types=1);

use App\Dto\Platforms\Eis\EisPolicy;
use App\Exceptions\Procurement\Platform\InvalidPlatformDefinition;

test('endpoint policy overrides legacy acquisition retry settings', function (): void {
    config(['crawler.stages.listing.retries' => 5,
        'platforms.eis.endpoints.listing.profile.attempts' => 2,
        'platforms.eis.endpoints.listing.profile.delays_ms' => [250]]);
    $profile = (new EisPolicy(config()))->endpoints->get('listing')->profile;

    expect($profile->attempts)->toBe(2)->and($profile->delaysMs)->toBe([250])
        ->and($profile->totalSeconds)->toBe(90);
});

test('listing and detail use independently configured typed endpoint policies', function (): void {
    config(['platforms.eis.endpoints.card.profile.total_seconds' => 20]);
    $endpoints = (new EisPolicy(config()))->endpoints;

    expect($endpoints->get('listing')->profile->totalSeconds)->toBe(90)
        ->and($endpoints->get('card')->profile->totalSeconds)->toBe(20);
});

test('acquisition endpoint defaults retain bounded attempts delay and timeout', function (): void {
    $profile = (new EisPolicy(config()))->endpoints->get('listing')->profile;

    expect($profile->attempts)->toBe(3)->and($profile->delaysMs)->toBe([200, 600])
        ->and($profile->connectSeconds)->toBe(5)->and($profile->totalSeconds)->toBe(90);
});

test('explicit zero retry delay survives typed endpoint policy without fallback', function (): void {
    config(['platforms.eis.endpoints.listing.profile.delays_ms' => [0, 600]]);
    $profile = (new EisPolicy(config()))->endpoints->get('listing')->profile;

    expect($profile->delaysMs)->toBe([0, 600])->and($profile->toArray()['delays_ms'])->toBe([0, 600]);
});

test('missing endpoint retry policy fails closed instead of reviving legacy defaults', function (): void {
    $profile = config('platforms.eis.endpoints.listing.profile');
    unset($profile['attempts']);
    config(['platforms.eis.endpoints.listing.profile' => $profile]);

    expect(fn () => new EisPolicy(config()))->toThrow(InvalidPlatformDefinition::class, 'requires exactly the declared keys');
});
