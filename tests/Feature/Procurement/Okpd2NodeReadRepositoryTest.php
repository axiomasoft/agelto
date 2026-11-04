<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Platform\Okpd2NodeReadRepository;

/**
 * read-репозиторий карты «код ОКПД2 → внутренний id НСИ».
 */
test('externalIds возвращает только запрошенные коды', function () {
    $platform = Platform::factory()->create();
    Okpd2Node::factory()->create(['platform_id' => $platform->id, 'code' => '43', 'external_id' => '8873921']);
    Okpd2Node::factory()->create(['platform_id' => $platform->id, 'code' => '43.2', 'external_id' => '8874105']);
    Okpd2Node::factory()->create(['platform_id' => $platform->id, 'code' => '41', 'external_id' => '8873919']);

    $result = app(Okpd2NodeReadRepository::class)->externalIds($platform->id, ['43', '43.2']);

    expect($result)->toBe(['43' => '8873921', '43.2' => '8874105']);
});

test('externalIds не находит несуществующий код и не бросает исключение', function () {
    $platform = Platform::factory()->create();
    Okpd2Node::factory()->create(['platform_id' => $platform->id, 'code' => '43', 'external_id' => '8873921']);

    $result = app(Okpd2NodeReadRepository::class)->externalIds($platform->id, ['43', '99.99']);

    expect($result)->toBe(['43' => '8873921']);
});

test('selectableNodes отдаёт узлы одной площадки и не видит чужую', function () {
    $platformA = Platform::factory()->create();
    $platformB = Platform::factory()->create();
    Okpd2Node::factory()->create(['platform_id' => $platformA->id, 'code' => '43']);
    Okpd2Node::factory()->create(['platform_id' => $platformA->id, 'code' => '41']);
    Okpd2Node::factory()->create(['platform_id' => $platformB->id, 'code' => '43']);

    $result = app(Okpd2NodeReadRepository::class)->selectableNodes($platformA->id);

    expect($result)->toHaveCount(2)
        ->and($result->pluck('code')->all())->toBe(['41', '43']);
});
