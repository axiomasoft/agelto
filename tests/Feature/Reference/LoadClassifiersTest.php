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

test('команда откатывает ранние чанки и справочники при ошибке в конце выгрузки', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'classifier_');
    assert(is_string($path));

    try {
        file_put_contents($path, "code,level,name,valid_from\n62.01,1,Первая запись,2024-01-01\n62.02,плохо,Последняя запись,2024-01-01\n");
        config([
            'reference.classifiers.okpd2.source_path' => fixtureOkpd2Path(),
            'reference.classifiers.ktru.source_path' => $path,
            'reference.classifiers.okved2.source_path' => null,
            'reference.classifiers.kbk.source_path' => null,
            'reference.import_chunk_size' => 1,
        ]);

        expect(fn () => $this->artisan('ref:load-classifiers')->run())->toThrow(RuntimeException::class, 'Некорректный level');
        expect(Okpd2::query()->count())->toBe(0)->and(Ktru::query()->count())->toBe(0);
    } finally {
        unlink($path);
    }
});

test('команда предупреждает о дубле внутри чанка и сохраняет последнюю строку', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'classifier_');
    assert(is_string($path));

    try {
        file_put_contents($path, "code,level,name,valid_from\n62.01,1,Первая запись,2024-01-01\n62.01,2,Последняя запись,2024-01-01\n");
        config(['reference.classifiers.okpd2.source_path' => $path, 'reference.import_chunk_size' => 2]);

        $this->artisan('ref:load-classifiers', ['classifier' => 'okpd2'])
            ->expectsOutput('Классификатор «okpd2»: повторяющиеся коды в чанке: 62.01.')
            ->assertSuccessful();

        $row = Okpd2::query()->sole();
        expect($row->name)->toBe('Последняя запись')->and($row->level)->toBe(2);
    } finally {
        unlink($path);
    }
});
