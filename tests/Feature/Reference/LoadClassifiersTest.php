<?php

declare(strict_types=1);

use App\Models\Reference\Ktru;
use App\Models\Reference\Okpd2;

function fixtureOkpd2Path(): string
{
    return base_path('tests/Fixtures/Reference/okpd2-sample.csv');
}

function fixtureKtruPath(): string
{
    return base_path('tests/Fixtures/Reference/ktru-sample.csv');
}

test('ref:load-classifiers наполняет ref_okpd2 из выгрузки', function () {
    config(['reference.classifiers.okpd2.source_path' => fixtureOkpd2Path()]);

    $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])->assertSuccessful();

    expect(Okpd2::query()->count())->toBe(3);

    $child = Okpd2::query()->where('code', '62.01.11')->sole();
    expect($child->parent_code)->toBe('62.01')
        ->and($child->level)->toBe(3)
        ->and($child->valid_from->toDateString())->toBe('2017-01-01')
        ->and($child->valid_to)->toBeNull();
});

test('ref:load-classifiers идемпотентен — повторный прогон не плодит строк', function () {
    config(['reference.classifiers.okpd2.source_path' => fixtureOkpd2Path()]);

    $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])->assertSuccessful();
    $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])->assertSuccessful();

    expect(Okpd2::query()->count())->toBe(3);
});

test('ref:load-classifiers пропускает классификатор без сконфигурированного пути', function () {
    config(['reference.classifiers.okpd2.source_path' => null]);

    $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])->assertSuccessful();

    expect(Okpd2::query()->count())->toBe(0);
});

test('ref:load-classifiers пропускает классификатор с несуществующим файлом', function () {
    config(['reference.classifiers.okpd2.source_path' => base_path('tests/Fixtures/Reference/does-not-exist.csv')]);

    $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])->assertSuccessful();

    expect(Okpd2::query()->count())->toBe(0);
});

test('ref:load-classifiers падает с ошибкой на неизвестном классификаторе', function () {
    $this->artisan('ref:load-classifiers', ['classifier' => 'unknown-classifier'])->assertFailed();
});

test('ref:load-classifiers без аргумента прогоняет все сконфигурированные классификаторы', function () {
    config([
        'reference.classifiers.okpd2.source_path' => fixtureOkpd2Path(),
        'reference.classifiers.ktru.source_path' => fixtureKtruPath(),
        'reference.classifiers.okved2.source_path' => null,
        'reference.classifiers.kbk.source_path' => null,
    ]);

    $this->artisan('ref:load-classifiers')->assertSuccessful();

    expect(Okpd2::query()->count())->toBe(3)
        ->and(Ktru::query()->count())->toBe(1);
});

test('ref:load-classifiers наполняет ref_ktru с okpd2_code и attributes без двойного JSON-кодирования', function () {
    config(['reference.classifiers.ktru.source_path' => fixtureKtruPath()]);

    $this->artisan('ref:load-classifiers', ['classifier' => 'ktru'])->assertSuccessful();

    $item = Ktru::query()->sole();
    expect($item->okpd2_code)->toBe('62.01.11')
        ->and($item->attributes)->toBe(['unit' => 'шт']);
});
