<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Procurement\AiDocumentType;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * P5.4 — ветка `ai:gold:build --screening` строит `gold/<CODE>.screening.json` по реестру
 * (§8.5): `class=neg` → вердикт из `Fixture.doc_type` (D5), `class=est` → `estimate`. Реестр —
 * единственный источник вердикта, команда его не толкует (Code Guidance) — фабрика заменяет
 * реальный корпус, реальный прогон — предмет Completion Notes.
 */
function screeningGoldDir(): string
{
    $folder = sys_get_temp_dir().'/screening-gold-'.Str::uuid();
    File::ensureDirectoryExists($folder);

    return $folder;
}

test('негатив с doc_type → файл с этим вердиктом и непустым ignore_reason', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->neg()->create([
        'code' => 'NEG-PDF-DRAWING-901',
        'doc_type' => AiDocumentType::Unknown,
        'notes' => 'Чертёж с полным текстовым слоем — самый опасный негатив корпуса',
    ]);

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['NEG-PDF-DRAWING-901']])
        ->assertExitCode(0);

    $screening = json_decode((string) file_get_contents("{$folder}/NEG-PDF-DRAWING-901.screening.json"), true);

    expect($screening['document_type'])->toBe('unknown')
        ->and($screening['ignore_reason'])->not->toBeNull()
        ->and($screening['confidence'])->toBe(1.0);

    File::deleteDirectory($folder);
});

test('негатив без doc_type → отклонение с названием кода', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->neg()->create([
        'code' => 'NEG-PDF-DRAWING-902',
        'doc_type' => null,
    ]);

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['NEG-PDF-DRAWING-902']])
        ->expectsOutputToContain('NEG-PDF-DRAWING-902')
        ->assertExitCode(1);

    expect(is_file("{$folder}/NEG-PDF-DRAWING-902.screening.json"))->toBeFalse();

    File::deleteDirectory($folder);
});

test('эталон-смета → вердикт estimate и ignore_reason=null', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-901']);

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-901']])
        ->assertExitCode(0);

    $screening = json_decode((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-901.screening.json"), true);

    expect($screening['document_type'])->toBe('estimate')
        ->and($screening['ignore_reason'])->toBeNull();

    File::deleteDirectory($folder);
});

test('extraction-gold того же эталона не перезаписан', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-902']);
    file_put_contents("{$folder}/EST-XLSX-SCREEN-902.gold.json", '{"estimates":[]}');

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-902']])
        ->assertExitCode(0);

    expect((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-902.gold.json"))->toBe('{"estimates":[]}')
        ->and(is_file("{$folder}/EST-XLSX-SCREEN-902.screening.json"))->toBeTrue();

    File::deleteDirectory($folder);
});

test('повторный прогон байт-идентичен', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-903']);
    $path = "{$folder}/EST-XLSX-SCREEN-903.screening.json";

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder])->assertExitCode(0);
    $first = (string) file_get_contents($path);

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder])->assertExitCode(0);
    $second = (string) file_get_contents($path);

    expect($second)->toBe($first);

    File::deleteDirectory($folder);
});

test('файл проходит ScreeningResult::validateAndCreate()', function (): void {
    $folder = screeningGoldDir();
    Fixture::factory()->est()->create(['code' => 'EST-XLSX-SCREEN-904']);

    $this->artisan('ai:gold:build', ['--screening' => true, '--gold-dir' => $folder, '--code' => ['EST-XLSX-SCREEN-904']])
        ->assertExitCode(0);

    $raw = json_decode((string) file_get_contents("{$folder}/EST-XLSX-SCREEN-904.screening.json"), true);

    expect(fn () => ScreeningResult::validateAndCreate($raw))->not->toThrow(Exception::class);

    File::deleteDirectory($folder);
});
