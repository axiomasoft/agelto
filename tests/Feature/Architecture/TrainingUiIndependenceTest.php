<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Витрина обучалки не знает про предметку типа документа (P5.4, D49) — СОБСТВЕННЫЙ гейт витрины:
 * arch-тест ядра (P2.6) стоит на неймспейсах ЯДРА и `App\Filament\**` не покрывает, то есть
 * поверхность, через которую знание о сметах возвращается в проект первым (экран результата),
 * машинно не защищена ничем другим.
 *
 * Форма гейта — та же, что доказала работоспособность в P2.6 (`findings/core-arch-gate-2026-07-30.md`
 * §3): периметр строится ДИНАМИЧЕСКИ Finder'ом (рукописный перечень молча перестаёт ловить новый
 * файл витрины), таргеты `toUse()` перебираются ПООДИНОЧКЕ (массив таргетов зеленеет, как только
 * чист ХОТЯ БЫ один класс списка), `->ignoring()` не используется. Текстовый пункт покрывает и
 * blade витрины: экран — это класс И его вёрстка, а «покажу колонку сметы» пишется как раз в blade.
 *
 * Список неймспейсов ЯДРА (arch-тест P2.6) этим тестом НЕ трогается (D49): у двух тестов разные
 * предметы — ядро пайплайна и витрина обучалки.
 */

/** Неймспейс витрины обучалки — ресурс эталонов целиком (страницы, схемы, relation-менеджеры). */
function trainingUiNamespace(): string
{
    return 'App\Filament\Resources\Procurement\Ai\Fixtures';
}

/**
 * Классы витрины как пары «класс → файл», собранные Finder'ом: новый файл витрины попадает в
 * периметр сам.
 *
 * @return list<array{class: class-string, path: string}>
 */
function trainingUiClasses(): array
{
    $namespace = trainingUiNamespace();
    $directory = app_path(str_replace('\\', '/', substr($namespace, strlen('App\\'))));

    $classes = [];

    foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
        /** @var class-string $class */
        $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

        $classes[] = ['class' => $class, 'path' => (string) $file->getRealPath()];
    }

    return $classes;
}

/**
 * Файлы вёрстки витрины — вторая половина периметра текстового пункта.
 *
 * @return list<string>
 */
function trainingUiViewFiles(): array
{
    $directory = resource_path('views/filament/resources/fixtures');

    if (! is_dir($directory)) {
        return [];
    }

    $paths = [];

    foreach (Finder::create()->files()->in($directory)->name('*.blade.php') as $file) {
        $paths[] = (string) $file->getRealPath();
    }

    return $paths;
}

test('периметр витрины обучалки собран Finder\'ом, а не перечнем руками', function (): void {
    // Счётчик — канарейка периметра: растёт при КАЖДОМ новом файле витрины. 10 = ресурс + 4
    // страницы + форма + инфолист + две схемы + две таблицы + relation-менеджер.
    expect(trainingUiClasses())->toHaveCount(12)
        ->and(trainingUiViewFiles())->toHaveCount(2);
});

test('витрина обучалки не импортирует предметку типа документа', function (): void {
    foreach (trainingUiClasses() as $entry) {
        expect($entry['class'])->not->toUse([
            'App\*\Procurement\Analyzers',
            'App\Dto\Procurement\Analyzers\Estimate',
            'App\Models\Procurement\Estimate',
        ]);
    }
});

test('витрина обучалки не несёт идентификатор Estimate, литерал \'estimate\' и имя таблицы prc_estimate_', function (): void {
    $violations = [];

    $files = [
        ...array_column(trainingUiClasses(), 'path'),
        ...trainingUiViewFiles(),
    ];

    foreach ($files as $path) {
        // `AiDocumentType::Estimate` — SSOT оси типа (D16) и в витрине законен ровно так же, как
        // в ядре (§6.3 контракта, пункт (г)): вырезается ДО проверки.
        $source = str_replace('AiDocumentType::Estimate', '', (string) file_get_contents($path));

        if (preg_match_all('/\bEstimate\b|\'estimate\'|prc_estimate_/', $source, $matches) > 0) {
            $violations[] = $path.': '.implode(', ', $matches[0]);
        }
    }

    expect($violations)->toBe([]);
});
