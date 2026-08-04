<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use App\Services\Procurement\Analyzers\Estimate\EstimateAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Doubles\ExtractionlessAnalyzer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(fn () => artisan('guard:sync-roles'));

test('валидный профиль пяти полей даёт пустой массив', function (): void {
    $errors = app(EstimateAnalyzer::class)->validateTypeProfile([
        'class' => FixtureClass::Estimate->value,
        'form' => 'KS2',
        'gold_source' => GoldSource::Deterministic->value,
        'expected_estimates' => 1,
        'expected_rows' => null,
    ]);

    expect($errors)->toBe([]);
});

test('профиль без class и с чужим gold_source даёт сообщения обоих полей за один вызов', function (): void {
    $errors = app(EstimateAnalyzer::class)->validateTypeProfile([
        'form' => 'KS2',
        'gold_source' => 'not-a-real-source',
    ]);

    expect($errors)->toHaveKeys(['class', 'gold_source'])
        ->and($errors['class'])->not->toBe([])
        ->and($errors['gold_source'])->not->toBe([]);
});

test('неизвестный ключ профиля — ошибка', function (): void {
    // Ровно тот отложенный ValueError, ради снятия которого существует item: `clas` вместо
    // `class` — не одна из пяти закрытых полей §6.2, ошибка обязана всплыть в форме, а не в
    // консольном потребителе часы спустя (Fixture::profileClass()).
    $errors = app(EstimateAnalyzer::class)->validateTypeProfile([
        'clas' => FixtureClass::Estimate->value,
        'form' => 'KS2',
        'gold_source' => GoldSource::Deterministic->value,
    ]);

    expect($errors)->toHaveKey('');
});

test('анализатор без реализации интерфейса не блокирует запись — форма сохраняет профиль как есть', function (): void {
    config(['ai.analyzers.estimate' => ExtractionlessAnalyzer::class]);
    // `DocumentAnalyzerRegistry` — контейнерный singleton (`AppServiceProvider::register()`);
    // `guard:sync-roles` в `beforeEach` уже мог прогреть его на дефолтном конфиге, поэтому
    // config-override без сброса инстанса тихо не сработал бы.
    app()->forgetInstance(DocumentAnalyzerRegistry::class);

    Storage::fake('local');
    Storage::fake('estimates');

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);

    Livewire::test(CreateFixture::class)
        ->fillForm([
            'source' => UploadedFile::fake()->create('source.xlsx', 100),
            'title' => 'Эталон без валидатора профиля',
            'document_type' => AiDocumentType::Estimate->value,
            'owner_document_type' => AiDocumentType::Estimate->value,
            // `class`/`form` остаются структурным требованием ЯДРА (`Fixture::nextCodeFor()`,
            // код эталона — D13, не предмет type-profile-валидации); `unexpected_key` — поле,
            // которое `EstimateAnalyzer::validateTypeProfile()` отклонил бы как неизвестное
            // (`clas`-кейс выше), но анализатор подменён и профиль сохраняется как есть.
            'type_profile' => [
                'class' => FixtureClass::Estimate->value,
                'form' => 'KS2',
                'unexpected_key' => 'anything',
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Fixture::query()->sole()->type_profile->get('unexpected_key'))->toBe('anything');
});
