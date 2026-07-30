<?php

declare(strict_types=1);

use App\Dto\Procurement\Analyzers\Estimate\EstimateHeaderMap;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Enums\Procurement\ResourceType;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\PromptContextGenerator;
use Database\Seeders\PromptSeeder;

beforeEach(function (): void {
    app(PromptSeeder::class)->run();
});

test('композиция каждого шага непуста, а шаги, читающие документ, несут блок безопасности', function (): void {
    $composer = app(PromptComposer::class);

    $readsDocument = [
        CorePromptStep::ScreeningClassify,
        EstimatePromptStep::ExtractionDiscovery,
        EstimatePromptStep::ExtractionPassport,
        EstimatePromptStep::ExtractionRows,
        EstimatePromptStep::ExtractionTotals,
    ];

    foreach ([...CorePromptStep::cases(), ...EstimatePromptStep::cases()] as $step) {
        $composed = $composer->compose(step: $step);

        expect($composed->systemPart)->not->toBe('');

        if (in_array($step, $readsDocument, strict: true)) {
            expect($composed->systemPart)->toContain('недоверенный вход');
        }
    }
});

test('часть правил каждого шага не превышает бюджет 12000 символов', function (): void {
    $composer = app(PromptComposer::class);

    foreach ([...CorePromptStep::cases(), ...EstimatePromptStep::cases()] as $step) {
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
        [CorePromptStep::ScreeningClassify, 'Несколько смет подряд в одном файле'],
        [EstimatePromptStep::ExtractionPassport, 'паспорт ровно ОДНОЙ сметы'],
        [EstimatePromptStep::ExtractionRows, 'у каждой сметы документа отсчёт начинается с 1'],
        [EstimatePromptStep::ExtractionTotals, 'подвал ЗАПРОШЕННОЙ сметы'],
        [EstimatePromptStep::ExtractionDiscovery, 'два маркера'],
    ];

    foreach ($multiEstimateRules as [$step, $rule]) {
        expect($composer->compose(step: $step)->systemPart)->toContain($rule);
    }
});

test('якорная фраза extraction:rows дословно совпадает с research/07', function (): void {
    $anchor = 'у каждой сметы документа отсчёт начинается с 1';

    $research = (string) file_get_contents(base_path('plans/2026.07.26-EST-TRAIN/research/07-multi-estimate-anchoring.md'));
    $prompt = (string) file_get_contents(resource_path('estimates/ai/prompts/extraction-rows/0-extraction-rows.md'));

    expect($research)->toContain($anchor);
    expect($prompt)->toContain($anchor);
});

test('печатный «№ п/п» не живёт в текстах промтов — словари только в #[Alias] и §2 спеки', function (): void {
    $files = [
        ...glob(resource_path('ai/prompts/*/0-*.md')) ?: [],
        ...glob(resource_path('*/ai/prompts/*/0-*.md')) ?: [],
    ];

    expect($files)->not->toBe([]);

    $offenders = array_values(array_filter(
        $files,
        fn (string $file): bool => str_contains((string) file_get_contents($file), '№ п/п'),
    ));

    expect($offenders)->toBe([]);
});
