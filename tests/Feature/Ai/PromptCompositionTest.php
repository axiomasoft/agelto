<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Estimate\EstimateHeaderMap;
use App\Enums\Procurement\PromptStep;
use App\Enums\Procurement\ResourceType;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\PromptContextGenerator;
use Database\Seeders\PromptSeeder;

beforeEach(function (): void {
    (new PromptSeeder)->run();
});

test('композиция каждого шага непуста, а шаги, читающие документ, несут блок безопасности', function (): void {
    $composer = app(PromptComposer::class);

    $readsDocument = [
        PromptStep::ScreeningClassify,
        PromptStep::ExtractionDiscovery,
        PromptStep::ExtractionPassport,
        PromptStep::ExtractionRows,
        PromptStep::ExtractionTotals,
    ];

    foreach (PromptStep::cases() as $step) {
        $composed = $composer->compose(step: $step);

        expect($composed->systemPart)->not->toBe('');

        if (in_array($step, $readsDocument, strict: true)) {
            expect($composed->systemPart)->toContain('недоверенный вход');
        }
    }
});

test('часть правил каждого шага не превышает бюджет 12000 символов', function (): void {
    $composer = app(PromptComposer::class);

    foreach (PromptStep::cases() as $step) {
        $rulesPart = $composer->compose(step: $step)->systemPart;

        expect(mb_strlen($rulesPart))->toBeLessThanOrEqual(12000);
    }
});

test('добавленные синонимы словарей присутствуют в сгенерированном блоке схем', function (): void {
    $block = app(PromptContextGenerator::class)->generate([EstimateHeaderMap::class, ResourceType::class]);

    foreach (['Номер п/п', '№№ п/п', 'Шифр расценки и коды ресурсов', 'Единица измерения', 'Кол-во единиц', 'всего в текущем уровне цен'] as $headerSynonym) {
        expect($block)->toContain($headerSynonym);
    }

    foreach (['ОТ(ЗТ)', 'ОТм(ЗТм)', 'М'] as $resourceSynonym) {
        expect($block)->toContain($resourceSynonym);
    }
});

test('ai:prompts:sync --check не находит дрейфа сразу после ai:prompts:sync', function (): void {
    $this->artisan('ai:prompts:sync')->assertExitCode(0);
    $this->artisan('ai:prompts:sync', ['--check' => true])->assertExitCode(0);
});

test('композиция каждого пошагового шага несёт своё правило многосметности или якорения', function (): void {
    $composer = app(PromptComposer::class);

    $multiEstimateRules = [
        [PromptStep::ScreeningClassify, 'Несколько смет подряд в одном файле'],
        [PromptStep::ExtractionPassport, 'паспорт ровно ОДНОЙ сметы'],
        [PromptStep::ExtractionRows, 'у каждой сметы документа отсчёт начинается с 1'],
        [PromptStep::ExtractionTotals, 'подвал ЗАПРОШЕННОЙ сметы'],
        [PromptStep::ExtractionDiscovery, 'два маркера'],
    ];

    foreach ($multiEstimateRules as [$step, $rule]) {
        expect($composer->compose(step: $step)->systemPart)->toContain($rule);
    }
});

test('якорная фраза extraction:rows дословно совпадает с research/07', function (): void {
    $anchor = 'у каждой сметы документа отсчёт начинается с 1';

    $research = (string) file_get_contents(base_path('plans/2026.07.26-EST-TRAIN/research/07-multi-estimate-anchoring.md'));
    $prompt = (string) file_get_contents(resource_path('ai/prompts/extraction-rows/0-extraction-rows.md'));

    expect($research)->toContain($anchor);
    expect($prompt)->toContain($anchor);
});

test('печатный «№ п/п» не живёт в текстах промтов — словари только в #[Alias] и §2 спеки', function (): void {
    $files = glob(resource_path('ai/prompts/*/0-*.md')) ?: [];

    expect($files)->not->toBe([]);

    $offenders = array_values(array_filter(
        $files,
        fn (string $file): bool => str_contains((string) file_get_contents($file), '№ п/п'),
    ));

    expect($offenders)->toBe([]);
});
