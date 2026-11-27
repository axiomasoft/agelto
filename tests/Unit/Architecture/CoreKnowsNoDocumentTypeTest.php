<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Tests\Support\Architecture\PlatformSourceRules;

/*
 * Ядро транспорта
 * и скрининга не имеет права знать про конкретный тип документа. Периметр — восемь неймспейсов
 * §6.2, вычитание — сегмент пути `\Analyzers\` (правило вычитания §6.2, не именованный список
 * файлов — иначе гейт молча перестаёт ловить новый файл ядра, Implementation Rule 9в). Форма
 * гейта — РАБОЧАЯ из `findings/core-arch-gate-2026-07-30.md` §3: таргеты `toUse()` перебираются
 * ПООДИНОЧКЕ (массив таргетов даёт гейт без красного исхода — проба D, `findings/…` §2),
 * `->ignoring()` не используется (ослепляет проверку `uses`-стороны, `findings/…` §2).
 */

/** @return list<string> */
function coreNamespacesOfContract(): array
{
    return [
        'App\Services\Procurement\Screening', 'App\Services\Procurement\Extraction',
        'App\Services\Llm', 'App\Services\Procurement\Contracts',
        'App\Enums\Llm', 'App\Repositories\Llm', 'App\Models\Llm', 'App\Jobs\Procurement',
        'App\Services\Procurement\Fixture', 'App\Support\Files',
    ];
}

/**
 * Периметр ядра как пары «класс → файл», собранные Finder'ом по восьми неймспейсам §6.2, за
 * вычетом сегмента `\Analyzers\` (типовая поверхность). Динамический сбор — не перечень руками:
 * новый файл ядра попадает в периметр сам, новый файл анализатора вычитается сам.
 *
 * @return list<array{class: class-string, path: string}>
 */
function coreClassesOfContract(): array
{
    $classes = [];

    foreach (coreNamespacesOfContract() as $namespace) {
        $directory = app_path(str_replace('\\', '/', substr($namespace, strlen('App\\'))));

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (str_contains($class, '\Analyzers\\')) {
                continue;
            }

            $classes[] = ['class' => $class, 'path' => $file->getRealPath()];
        }
    }

    return $classes;
}

test('периметр ядра собран правилом вычитания §6.2, не перечнем руками', function (): void {
    $classes = coreClassesOfContract();

    // 108 = 107 + `App\Services\Procurement\Contracts\ListingSchemaAware` (preflight заголовка
    // листинга): объявляет обязательные колонки маппера, о типе документа не знает.
    // 107 = 105 + два provider-agnostic resource/lifecycle контракта:
    // `App\Support\Files\PipelineTemporaryFile` (единый managed temporary workspace) и
    // `App\Services\Procurement\Contracts\DeadlineAwareSourceMethod` (transport deadline seam).
    // 105 = 103 + два provider-agnostic document lifecycle job:
    // `DownloadDocumentJob` и `RevalidateDocumentJob`; оба работают с общим Document contract
    // и не импортируют поверхность конкретного типа документа.
    // Историческая detail job входила в счётчик 103; передаёт исполнение Process,
    // а документные обязательства создаются в fenced publication без inline dispatch.
    // 102 = 98 + четыре файла ядра: `App\Enums\Llm\FinishReason` (терминальный исход LLM),
    // `App\Jobs\Procurement\Crawler\CrawlRunJob` (очередной прогон),
    // `App\Services\Procurement\Proxy\OutboundProxy` (транспортный прокси) и
    // `App\Services\Procurement\Contracts\ContractAttributeReader` (чтение атрибутов контракта).
    // 98 = 97 + один новый файл ядра: `App\Services\Llm\Schema\DataTypeGraph` —
    // единственный владелец правила обхода графа типов, общий с JSON-схемой; правило обходит
    // Data-классы и enum'ы рефлексией и о типе документа не знает (сметные словари объявляет сам
    // тип, `App\Services\Procurement\Analyzers\Estimate\Core\EstimateStepDictionaries`).
    // 97 = 95 + два файла ядра: `App\Services\Llm\LlmPayloadReader` (чтение
    // сохранённого payload'а) и `App\Dto\Llm\Reporting\StoredPayload` (DTO трёх штатных состояний)
    // — домен транспорта `Llm` кросс-документен по контракту, витрина финального промта
    // читает вызовы ОБОИХ шагов, типовой специфики в этих файлах нет.
    // Счётчик — канарейка периметра: растёт при КАЖДОМ новом файле ядра.
    // 109 = 108 + platform-neutral DetailParser contract.
    // 110 = 109 + CooperativeListingSourceMethod (60d6162c): cooperative
    // listing progress — нейтральный transport contract, не тип документа.
    // 111 = 110 + ExecuteWorkJob: общая доставка work envelope без знания типа документа.
    // 113 = 111 + generic Llm endpoint declaration and configured provider reader.
    // 114 = 113 + generic DiagnosticSourceMethod full-validation capability.
    // removes two acquisition jobs and their obsolete cooperative contract.
    // removes three ordinary document delivery jobs; Process owns their stages.
    // Canonical bytea hashing adds the type-neutral Sha256Cast at the Eloquent boundary.
    // adds ten provider-neutral schema/task validation and staged extraction contract classes.
    // adds twelve provider-neutral financial/receipt and consumer coordination classes.
    // adds twelve provider-neutral wire, send capability and joint owner classes.
    // adds five provider-neutral asset lifecycle/policy components and Gemini codecs.
    // 164 = 165 - two provider stubs + CatalogOnlyProvider.
    // 165 = 164 + type-neutral UntrustedDocumentSanitizer at the extraction boundary.
    // 166 = 165 + BillingExportCsvReader extracted from BillingReconciler.
    // 145 = 166 - 21 App\Services\Llm\Dto\* moved to App\Dto\Llm\*.
    // 144 = 145 - JointProviderSendRejected moved to App\Exceptions\Procurement\Ai\*.
    // 151 = 144 + AuthHeaderScheme, ProviderWireCodec, ProviderCodecs, OpenAiStrictSchemaCompiler, OpenAiChatCompletionsClient, OpenAiReceiptPolicy, GeminiWireCodec.
    expect($classes)->toHaveCount(151)
        ->and(array_column($classes, 'class'))->toContain('App\Services\Procurement\Contracts\DiagnosticSourceMethod')
        ->and(array_column($classes, 'class'))->toContain('App\Jobs\Procurement\Process\ExecuteWorkJob')
        ->and(array_column($classes, 'class'))->toContain('App\Services\Llm\Providers\CatalogOnlyProvider')
        ->and(array_column($classes, 'class'))->not->toContain('App\Services\Llm\Providers\UnsupportedProvider', 'App\Services\Llm\Providers\OpenAiCompatibleProvider')
        ->and(array_column($classes, 'class'))->not->toContain('App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob');
});

test('ядро не импортирует поверхность типа документа — (а)/(б)/(в) §6.3', function (): void {
    foreach (coreClassesOfContract() as $entry) {
        expect($entry['class'])->not->toUse([
            'App\*\Procurement\Analyzers',
            'App\Dto\Procurement\Analyzers\Estimate',
            'App\Models\Procurement\Estimate',
        ]);
    }
});

test('новый Llm execution port не импортирует Procurement и Gemini codec не отправляет HTTP', function (): void {
    foreach (File::allFiles(app_path('Services/Llm/Execution')) as $file) {
        $source = $file->getContents();
        expect($source)->not->toContain('App\\Services\\Procurement', 'App\\Repositories\\Procurement', 'App\\Dto\\Procurement');
    }
    $codec = file_get_contents(app_path('Services/Llm/Providers/GeminiGenerateContentClient.php'));
    expect($codec)->not->toContain('Facades\\Http', 'PendingRequest', 'Prism\\', 'ProxyResolver', '->send(', '->retry(');
});

test('provider layers не знают тип документа а analyzer pipeline не зависит от площадки', function (): void {
    foreach (['Services/Llm', 'Repositories/Llm', 'Models/Llm', 'Dto/Llm'] as $root) {
        foreach (File::allFiles(app_path($root)) as $file) {
            $dependencies = PlatformSourceRules::inspect($file->getContents())['dependencies'];
            expect(array_values(array_filter($dependencies, fn (string $d): bool => str_contains($d, '\\Procurement\\'))))->toBe([]);
        }
    }
    foreach (['Services/Procurement/Extraction', 'Services/Procurement/Screening', 'Services/Procurement/Analyzers/Estimate'] as $root) {
        foreach (File::allFiles(app_path($root)) as $file) {
            $dependencies = PlatformSourceRules::inspect($file->getContents())['dependencies'];
            expect(array_values(array_filter($dependencies, fn (string $d): bool => str_contains($d, '\\Platforms\\'))))->toBe([]);
        }
    }
});
