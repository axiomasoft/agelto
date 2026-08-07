<?php

declare(strict_types=1);

use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use Database\Seeders\Procurement\Okpd2NodeSeeder;

/**
 * P2.1 (D19) — сид карты «код ОКПД2 → внутренний id НСИ» из промоушен-датафайла P1.2.
 */
test('сид наполняет карту узлов площадки eis из датафайла', function () {
    Platform::factory()->create(['code' => 'eis']);

    $this->seed(Okpd2NodeSeeder::class);

    expect(Okpd2Node::query()->count())->toBe(34)
        ->and(Okpd2Node::query()->where('code', '43')->first()->external_id)->toBe('8873921');
});

test('повторный прогон сидера идемпотентен — число строк не меняется', function () {
    Platform::factory()->create(['code' => 'eis']);

    $this->seed(Okpd2NodeSeeder::class);
    $this->seed(Okpd2NodeSeeder::class);

    expect(Okpd2Node::query()->count())->toBe(34);
});

test('сид не заполняет synced_at — сид не сверяет данные с площадкой', function () {
    Platform::factory()->create(['code' => 'eis']);

    $this->seed(Okpd2NodeSeeder::class);

    expect(Okpd2Node::query()->whereNotNull('synced_at')->count())->toBe(0);
});

test('без площадки eis сид бросает исключение', function () {
    $this->seed(Okpd2NodeSeeder::class);
})->throws(RuntimeException::class, 'Okpd2NodeSeeder: площадка eis не найдена — прогоните EisPlatformSeeder первым.');
