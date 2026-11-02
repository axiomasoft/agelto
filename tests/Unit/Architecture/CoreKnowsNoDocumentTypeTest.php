<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/*
 * Приёмочное свойство D14/D26 (§6.2–§6.3 `document-analyzer-contract.md`, P2.1): ядро транспорта
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
    // Историческая detail job входила в счётчик 103; P2.18 передаёт исполнение Process,
    // а документные обязательства создаются в fenced publication без inline dispatch.
    // 102 = 98 + четыре файла ядра: `App\Enums\Llm\FinishReason` (терминальный исход LLM),
    // `App\Jobs\Procurement\Crawler\CrawlRunJob` (очередной прогон),
    // `App\Services\Procurement\Proxy\OutboundProxy` (транспортный прокси) и
    // `App\Services\Procurement\Contracts\ContractAttributeReader` (чтение атрибутов контракта).
    // 98 = 97 + один новый файл ядра (P6.1, D51): `App\Services\Llm\Schema\DataTypeGraph` —
    // единственный владелец правила обхода графа типов, общий с JSON-схемой; правило обходит
    // Data-классы и enum'ы рефлексией и о типе документа не знает (сметные словари объявляет сам
    // тип, `App\Services\Procurement\Analyzers\Estimate\EstimateStepDictionaries`).
    // 97 = 95 + два файла ядра (P5.5, D46): `App\Services\Llm\LlmPayloadReader` (чтение
    // сохранённого payload'а) и `App\Services\Llm\Dto\StoredPayload` (DTO трёх штатных состояний)
    // — домен транспорта `Llm` кросс-документен по контракту (D22), витрина финального промта
    // читает вызовы ОБОИХ шагов, типовой специфики в этих файлах нет.
    // Счётчик — канарейка периметра: растёт при КАЖДОМ новом файле ядра.
    // 109 = 108 + platform-neutral DetailParser contract.
    // 110 = 109 + CooperativeListingSourceMethod (60d6162c, до P4): cooperative
    // listing progress — нейтральный transport contract, не тип документа.
    // 111 = 110 + ExecuteWorkJob (P1.10): общая доставка work envelope без знания типа документа.
    // 113 = 111 + generic Llm endpoint declaration and configured provider reader (P2.6).
    // 114 = 113 + generic DiagnosticSourceMethod full-validation capability (D49).
    // P2.18 removes two acquisition jobs and their obsolete cooperative contract.
    // P3.5 removes three ordinary document delivery jobs; Process owns their stages.
    // Canonical bytea hashing adds the type-neutral Sha256Cast at the Eloquent boundary.
    // P3.8 adds ten provider-neutral schema/task validation and staged extraction contract classes.
    // P3.9 adds twelve provider-neutral financial/receipt and consumer coordination classes.
    // P3.10 adds twelve provider-neutral wire, send capability and joint owner classes.
    // P3.12 adds five provider-neutral asset lifecycle/policy components and Gemini codecs.
    expect($classes)->toHaveCount(148)
        ->and(array_column($classes, 'class'))->toContain('App\Services\Procurement\Contracts\DiagnosticSourceMethod')
        ->and(array_column($classes, 'class'))->toContain('App\Jobs\Procurement\Process\ExecuteWorkJob')
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

test('ядро не несёт идентификатор Estimate/литерал \'estimate\' кроме AiDocumentType::Estimate — (г) §6.3', function (): void {
    $violations = [];

    foreach (coreClassesOfContract() as $entry) {
        $source = str_replace('AiDocumentType::Estimate', '', (string) file_get_contents($entry['path']));

        if (preg_match_all('/\bEstimate\b|\'estimate\'/', $source, $matches) > 0) {
            $violations[] = $entry['path'].': '.implode(', ', $matches[0]);
        }
    }

    expect($violations)->toBe([]);
});
