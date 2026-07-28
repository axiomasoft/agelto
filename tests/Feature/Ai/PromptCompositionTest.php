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
