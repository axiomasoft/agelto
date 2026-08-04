<?php

declare(strict_types=1);

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\CreateFixture;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

// PHP-роли зеркалятся в таблицу roles до назначения (class_name → assignRole).
beforeEach(function (): void {
    artisan('guard:sync-roles');

    Storage::fake('local');
    Storage::fake('estimates');

    $this->operator = User::factory()->create();
    $this->operator->assignRole(OperatorRole::class);
    actingAs($this->operator);
});

function fillFixtureForm(string $filename, string $content, string $form = 'KS2', string $title = 'Эталон'): array
{
    return [
        'source' => UploadedFile::fake()->createWithContent($filename, $content),
        'title' => $title,
        'document_type' => AiDocumentType::Estimate->value,
        'owner_document_type' => AiDocumentType::Estimate->value,
        'type_profile' => [
            'class' => FixtureClass::Estimate->value,
            'form' => $form,
            'gold_source' => GoldSource::Deterministic->value,
        ],
    ];
}

test('файл лёг по конвенции и запись создана', function (): void {
    Livewire::test(CreateFixture::class)
        ->fillForm(fillFixtureForm('source.xlsx', 'содержимое-1'))
        ->call('create')
        ->assertHasNoFormErrors();

    $fixture = Fixture::query()->sole();

    expect($fixture->storage_path)->toBe(Fixture::storagePathFor($fixture->code, 'xlsx'))
        ->and(Storage::disk('estimates')->exists($fixture->storage_path))->toBeTrue()
        ->and($fixture->sha256)->toBe(hash('sha256', 'содержимое-1'));
});

test('повторная загрузка того же файла отклонена и записи не создала', function (): void {
    Livewire::test(CreateFixture::class)
        ->fillForm(fillFixtureForm('source.xlsx', 'один и тот же файл'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Fixture::query()->count())->toBe(1);

    Livewire::test(CreateFixture::class)
        ->fillForm(fillFixtureForm('another-name.xlsx', 'один и тот же файл'))
        ->call('create')
        ->assertNotified();

    expect(Fixture::query()->count())->toBe(1);
});

test('код инкрементируется в пределах тройки класс/формат/форма и матчит CODE_PATTERN', function (): void {
    Livewire::test(CreateFixture::class)
        ->fillForm(fillFixtureForm('first.xlsx', 'первый файл'))
        ->call('create')
        ->assertHasNoFormErrors();

    Livewire::test(CreateFixture::class)
        ->fillForm(fillFixtureForm('second.xlsx', 'второй файл'))
        ->call('create')
        ->assertHasNoFormErrors();

    $codes = Fixture::query()->orderBy('id')->pluck('code')->all();

    expect($codes)->toBe(['EST-XLSX-KS2-001', 'EST-XLSX-KS2-002'])
        ->and(preg_match(Fixture::CODE_PATTERN, $codes[0]))->toBe(1)
        ->and(preg_match(Fixture::CODE_PATTERN, $codes[1]))->toBe(1);
});
