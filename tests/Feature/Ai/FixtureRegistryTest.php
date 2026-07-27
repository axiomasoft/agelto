<?php

declare(strict_types=1);

use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\FixtureStoreRepository;
use Illuminate\Database\QueryException;

test('сохраняет и читает все типизированные поля эталона', function (): void {
    $fixture = Fixture::factory()->create([
        'code' => 'EST-XLSX-KS2-001',
        'class' => FixtureClass::Estimate,
        'gold_source' => GoldSource::Deterministic,
        'quirks' => ['TWO_LEVEL_PRICE', 'HEADER_TWO_ROW'],
        'expected_estimates' => 1,
        'is_verified' => true,
        'verified_at' => now(),
    ]);

    $fresh = $fixture->fresh();

    expect($fresh->class)->toBe(FixtureClass::Estimate)
        ->and($fresh->gold_source)->toBe(GoldSource::Deterministic)
        ->and($fresh->quirks->all())->toBe(['TWO_LEVEL_PRICE', 'HEADER_TWO_ROW'])
        ->and($fresh->expected_estimates)->toBe(1)
        ->and($fresh->is_verified)->toBeTrue()
        ->and($fresh->verified_at)->not->toBeNull();
});

test('код эталона уникален', function (): void {
    Fixture::factory()->create(['code' => 'EST-XLSX-KS2-001']);

    expect(fn () => Fixture::factory()->create(['code' => 'EST-XLSX-KS2-001']))
        ->toThrow(QueryException::class);
});

test('sha256 эталона уникален', function (): void {
    Fixture::factory()->create(['code' => 'EST-XLSX-KS2-001', 'sha256' => str_repeat('a', 64)]);

    expect(fn () => Fixture::factory()->create(['code' => 'EST-XLSX-KS2-002', 'sha256' => str_repeat('a', 64)]))
        ->toThrow(QueryException::class);
});

test('storeOrUpdate идемпотентен по коду', function (): void {
    $repository = new FixtureStoreRepository;

    $first = $repository->storeOrUpdate('EST-XLSX-KS2-001', Fixture::factory()->raw(['code' => 'EST-XLSX-KS2-001']));
    $second = $repository->storeOrUpdate('EST-XLSX-KS2-001', Fixture::factory()->raw(['code' => 'EST-XLSX-KS2-001', 'notes' => 'обновлено']));

    expect(Fixture::query()->count())->toBe(1)
        ->and($first->id)->toBe($second->id)
        ->and($second->fresh()->notes)->toBe('обновлено');
});

test('storagePathFor строит путь исходного файла эталона', function (): void {
    expect(Fixture::storagePathFor('EST-XLSX-KS2-001', 'xlsx'))->toBe('EST-XLSX-KS2-001/source.xlsx');
});

test('derivedPathFor строит путь производного представления эталона', function (): void {
    expect(Fixture::derivedPathFor('EST-XLSX-KS2-001', 'pdf_alt.pdf'))->toBe('EST-XLSX-KS2-001/derived/pdf_alt.pdf');
});

test('связь DatasetExample->fixture резолвится', function (): void {
    $fixture = Fixture::factory()->create();
    $example = DatasetExample::factory()->create(['fixture_id' => $fixture->id]);

    expect($example->fixture->is($fixture))->toBeTrue();
});
