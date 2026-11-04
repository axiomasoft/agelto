<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * ветка `estimate:gold:build --screening` строит `gold/<CODE>.screening.json` по реестру
 * вердикт — `Fixture.document_type` для ЛЮБОГО класса: колонка `NOT NULL` заполнена и для позитивов;
 * classification note — только у негативов. Реестр —
 * единственный источник вердикта, команда его не толкует (Code Guidance) — фабрика заменяет
 * реальный корпус, реальный прогон — предмет Completion Notes.
 *
 * Тест «негатив без определённого типа → отклонение» снят ось стала `NOT NULL`
 * (§6.1), сценарий структурно недостижим — БД отклоняет саму запись реестра до появления
 * скрининг-gold'а (покрыто негативным тестом check-constraint'а, Validation 5 item'а).
 */
function screeningGoldDir(): string
{
    $folder = sys_get_temp_dir().'/screening-gold-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

test('негатив с document_type → файл с этим вердиктом и неавторитетной classification note', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->neg()->create([
        'code' => 'NEG-PDF-DRAWING-901',
        'document_type' => AiDocumentType::Unknown,
        'notes' => 'Чертёж с полным текстовым слоем — самый опасный негатив корпуса',
    ]);

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['NEG-PDF-DRAWING-901']])
        ->assertExitCode(0);

    $screening = json_decode((string) file_get_contents("{$folder}/NEG-PDF-DRAWING-901.screening.json"), true);

    expect($screening['document_type'])->toBe('unknown')
        ->and($screening['classification_note'])->not->toBeNull()
        ->and($screening['estimate_cardinality'])->toBe('none')
        ->and($screening['tags'])->toBe([])
        ->and($screening['confidence'])->toBe(1.0);

    File::deleteDirectory($folder);
});

test('эталон-смета → вердикт estimate, single cardinality и пустые tags', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-901']);

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-901']])
        ->assertExitCode(0);

    $screening = json_decode((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-901.screening.json"), true);

    expect($screening['document_type'])->toBe('estimate')
        ->and($screening['estimate_cardinality'])->toBe('single')
        ->and($screening['tags'])->toBe([])
        ->and($screening['classification_note'])->toBeNull();

    File::deleteDirectory($folder);
});

test('extraction-gold того же эталона не перезаписан', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-902']);
    file_put_contents("{$folder}/EST-XLSX-SCREEN-902.gold.json", '{"estimates":[]}');

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-902']])
        ->assertExitCode(0);

    expect((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-902.gold.json"))->toBe('{"estimates":[]}')
        ->and(is_file("{$folder}/EST-XLSX-SCREEN-902.screening.json"))->toBeTrue();

    File::deleteDirectory($folder);
});

test('повторный прогон байт-идентичен', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-903']);
    $path = "{$folder}/EST-XLSX-SCREEN-903.screening.json";

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder])->assertExitCode(0);
    $first = (string) file_get_contents($path);

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder])->assertExitCode(0);
    $second = (string) file_get_contents($path);

    expect($second)->toBe($first);

    File::deleteDirectory($folder);
});

test('файл проходит ScreeningResult::validateAndCreate()', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-904']);

    $this->artisan('estimate:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-904']])
        ->assertExitCode(0);

    $raw = json_decode((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-904.screening.json"), true);

    expect(fn () => ScreeningResult::validateAndCreate($raw))->not->toThrow(Exception::class);

    File::deleteDirectory($folder);
});
