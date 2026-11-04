<?php

declare(strict_types=1);

use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Dto\Procurement\Analyzers\Estimate\DiscoveryResponse;
use App\Dto\Procurement\Analyzers\Estimate\PassportResponse;
use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Dto\Procurement\Analyzers\Estimate\TotalsResponse;
use App\Enums\Llm\CorePromptStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Services\Llm\PromptComposer;
use App\Services\Llm\Schema\JsonSchemaGenerator;
use App\Services\Procurement\Analyzers\Estimate\EstimateStepDictionaries;
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

test('синонимы словарей доезжают до боевой системной части шага строк', function (): void {
    $step = EstimatePromptStep::ExtractionRows;
    $dictionaries = app(EstimateStepDictionaries::class);

    // Ровно то, что собирают боевые потребители шага (RowRangeChunkSource, WindowedChunkSource,
    // DatasetExporter): обёртка ответа + словари, объявленные ТИПОМ. Список словарей руками сюда
    // не переписывается — иначе тест был бы зелен по построению, .
    $systemPart = app(PromptComposer::class)
        ->compose($step, [RowsChunkResponse::class, ...$dictionaries->forStep($step)])
        ->systemPart;

    foreach (['Номер п/п', '№№ п/п', 'Шифр расценки и коды ресурсов', 'Единица измерения', 'Кол-во единиц', 'всего в текущем уровне цен'] as $headerSynonym) {
        expect($systemPart)->toContain($headerSynonym);
    }

    // Словарь ресурсов приезжает ЗАМЫКАНИЕМ (RowsChunkResponse → EstimateRow → RowResource),
    // а не объявлением — обе половины проверяются одним боевым составом.
    foreach (['ОТ(ЗТ)', 'ОТм(ЗТм)', 'ФССЦ'] as $resourceSynonym) {
        expect($systemPart)->toContain($resourceSynonym);
    }

    // Форма носителя — выбранная OQ-2 (вариант C): типизированный рендер по атрибутам,
    // а не PHP-исходник и не проза промта.
    expect($systemPart)->toContain('- row_index: «№ п/п»')
        ->and($systemPart)->toContain('- labor: «ЗП»');
});

test('системная часть ни одного шага не несёт исходника контракта ответа (шаг 3)', function (): void {
    $composer = app(PromptComposer::class);
    $dictionaries = app(EstimateStepDictionaries::class);

    $steps = [
        [CorePromptStep::ScreeningClassify, ScreeningResult::class],
        [EstimatePromptStep::ExtractionDiscovery, DiscoveryResponse::class],
        [EstimatePromptStep::ExtractionPassport, PassportResponse::class],
        [EstimatePromptStep::ExtractionRows, RowsChunkResponse::class],
        [EstimatePromptStep::ExtractionTotals, TotalsResponse::class],
    ];

    foreach ($steps as [$step, $root]) {
        // Состав — тот же, что у боевых потребителей шага: корень + словари, объявленные ТИПОМ.
        $systemPart = $composer->compose($step, [$root, ...$dictionaries->forStep($step)])->systemPart;

        // Структуру контракта несёт ИСХОДЯЩАЯ СХЕМА (`response_schema` + `#[Description]`,
        // /). Маркер печати исходника v1 и его синтаксические следы в системной части
        // означали бы возврат второго носителя той же структуры.
        foreach (['// --- Source:', 'final class', 'extends Data', 'public function __construct'] as $marker) {
            expect([$step->value, $marker, str_contains($systemPart, $marker)])->toBe([$step->value, $marker, false]);
        }
    }
});

test('каждое свойство схемы шага строк несёт description — семантика уехала схемой, не промтом', function (): void {
    $schemas = app(JsonSchemaGenerator::class);

    $schema = $schemas->generate(RowsChunkResponse::class);

    // Пара к тесту выше: печать исходника снята, и единственный носитель семантики поля —
    // `description` исходящей схемы. Пустое покрытие здесь означало бы, что модель получила имена
    // полей без единого пояснения.
    foreach ($schema['$defs']['EstimateRow']['properties'] as $property => $node) {
        expect([$property, isset($node['description']) || isset($node['$ref']) || isset($node['anyOf'])])->toBe([$property, true]);
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

test('якорная фраза extraction:rows дословно совпадает с постоянной фикстурой инварианта', function (): void {
    $anchor = trim((string) file_get_contents(base_path('tests/Fixtures/estimate-row-anchoring.md')));

    $prompt = (string) file_get_contents(resource_path('estimates/ai/prompts/extraction-rows/0-extraction-rows.md'));

    expect($anchor)->toBe('у каждой сметы документа отсчёт начинается с 1')
        ->and($prompt)->toContain($anchor);
});

test('И1/И4 — правило полноты шага строк живёт дословным императивом', function (): void {
    // §8.14 регрессия 3: редакция M4 заменила команду «Включай ВСЕ строки таблицы» на описание
    // «Во фрагмент входят ВСЕ строки таблицы» — из утверждения о свойствах фрагмента обязанность
    // вернуть строки не следует, и покрытие holdout упало на 0.4796. Якорь дословный: если
    // формулировку снова превратят в описание, красным станет тест, а не платный прогон.
    $systemPart = app(PromptComposer::class)->compose(step: EstimatePromptStep::ExtractionRows)->systemPart;

    expect($systemPart)->toContain('Включай ВСЕ строки таблицы')
        ->and($systemPart)->toContain('заголовки разделов/подразделов и промежуточные итоги наравне с позициями расценок');
});

test('И2 — право на пустоту в global адресует значение поля, а не коллекцию ответа', function (): void {
    // §8.14 регрессия 1: сквозное правило global расширило право на пустоту с ПОЛЯ на СПИСОК
    // («а для списка — пустой список», «пустой ответ … всегда лучше»). Ответ шага строк и есть
    // список — расширение легализовало пустой ответ как штатный. Проверка негативная: этих
    // конструкций в global быть не должно ни в какой переформулировке.
    $globalPart = app(PromptComposer::class)->compose(step: CorePromptStep::Global)->systemPart;

    foreach (['пустой список', 'всегда лучше', 'пустой ответ'] as $forbidden) {
        expect([$forbidden, str_contains($globalPart, $forbidden)])->toBe([$forbidden, false]);
    }
});

test('И3 — триггер пропуска в global остаётся закрытым списком v2', function (): void {
    // §8.14 регрессия 2: «неразборчивое» (свойство печати — не видно) заменили на «неоднозначное»
    // (свойство трактовки — видно, но читается двояко), и триггер накрыл несравнимо больше строк
    // сметы. Позитивная проверка держит закрытый список, негативная — не даёт его расширить.
    $globalPart = app(PromptComposer::class)->compose(step: CorePromptStep::Global)->systemPart;

    expect($globalPart)->toContain('Нечитаемое, отсутствующее или неразборчивое значение поля')
        ->and(str_contains($globalPart, 'неоднозначн'))->toBeFalse();
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
