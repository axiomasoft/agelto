<?php

declare(strict_types=1);

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

    expect($classes)->toHaveCount(70)
        ->and(array_column($classes, 'class'))->not->toContain('App\Jobs\Procurement\Analyzers\Estimate\EstimateExtractionJob');
});

test('ядро не импортирует поверхность типа документа — (а)/(б)/(в) §6.3', function (): void {
    foreach (coreClassesOfContract() as $entry) {
        expect($entry['class'])->not->toUse([
            'App\*\Procurement\Analyzers',
            'App\Dto\Procurement\Ai\Estimate',
            'App\Models\Procurement\Estimate',
        ]);
    }
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
