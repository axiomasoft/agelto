<?php

declare(strict_types=1);

use App\Filament\Resources\Procurement\Ai\Fixtures\Pages\ViewFixtureGold;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\Procurement\Ai\Fixture;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    artisan('guard:sync-roles');
    $this->goldDirectory = sys_get_temp_dir().'/fixture-gold-'.uniqid();
    mkdir($this->goldDirectory);
    $this->manifestPath = $this->goldDirectory.'/manifest.json';
    config([
        'ai.fixtures.gold_dir' => $this->goldDirectory,
        'ai.fixtures.manifest_path' => $this->manifestPath,
    ]);

    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    actingAs($operator);
});

afterEach(function (): void {
    foreach (glob($this->goldDirectory.'/*') ?: [] as $file) {
        unlink($file);
    }

    rmdir($this->goldDirectory);
});

function fixtureGoldPagePayload(): array
{
    return [
        'estimates' => [[
            'passport' => ['address' => 'Тестовый адрес'],
            'estimate_number' => 'ЛС-1',
            'rows' => [[
                'row_index' => 1,
                'row_type' => 'item',
                'name' => 'Работа витрины',
                'resources' => [],
            ]],
        ]],
    ];
}

test('страница показывает паспорт и позиции gold-файла', function (): void {
    $fixture = Fixture::factory()->est()->create(['code' => 'EST-XLSX-KS2-001']);
    file_put_contents($this->goldDirectory.'/EST-XLSX-KS2-001.gold.json', json_encode(fixtureGoldPagePayload(), JSON_THROW_ON_ERROR));
    file_put_contents($this->manifestPath, json_encode(['version' => 1, 'fixtures' => [['code' => $fixture->code, 'gold_path' => 'gold/file']]], JSON_THROW_ON_ERROR));

    Livewire::test(ViewFixtureGold::class, ['record' => $fixture->getKey()])
        ->assertSuccessful()
        ->assertSee('ЛС-1')
        ->assertSee('Работа витрины');
});

test('страница объясняет расхождение манифеста и файла', function (): void {
    $fixture = Fixture::factory()->est()->create(['code' => 'EST-XLSX-KS2-001']);
    file_put_contents($this->manifestPath, json_encode(['version' => 1, 'fixtures' => [['code' => $fixture->code, 'gold_path' => 'gold/file']]], JSON_THROW_ON_ERROR));

    Livewire::test(ViewFixtureGold::class, ['record' => $fixture->getKey()])
        ->assertSuccessful()
        ->assertSee('Манифест обещает gold-файл');
});
