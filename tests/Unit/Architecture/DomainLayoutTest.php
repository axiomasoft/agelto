<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/** Проверяет пути, namespaces и допустимые подобласти доменов по текущему дереву. */

/**
 * @return array<string, array{root: list<string>, subareas: list<string>}>
 */
function alignedDomainLayoutLayers(): array
{
    return [
        // , Completion Notes: 7 корневых (6 ядро + `OperationLogLevel` кросс), Completion Notes .
        'App\Enums\Procurement' => [
            'root' => [
                'AwardStatus', 'BidStatus', 'ProcurementEventType',
                'ProcurementStatus', 'ProtocolType', 'OperationLogLevel', 'StatusMappingApplicability',
                'DetailSnapshotStatus',
            ],
            // подобласть `Network` — typed network authority/transport enums (structure#s01).
            // Platform — neutral adapter assessment/completeness enums (structure#s01).
            'subareas' => ['Ai', 'Analyzers', 'Crawler', 'Document', 'Estimate', 'Health', 'Network', 'Parsing', 'Platform', 'Process', 'Proxy'],
        ],
        // , Completion Notes: `OperationFailed` (базовый, кросс) + `UnknownReportFormat` (кросс).
        // `InvalidEvalDiffPayload` обслуживает корневой estimate CLI-контур, а не отдельную подобласть.
        // подобласть `Crawler` заведена первым файлом (`CrawlerRunAlreadyActive`).
        // Prepare: OperationBudgetExceeded зеркалит существующие Services/Dto\Procurement\Prepare.
        // Process — typed control exits handler (LeaseLost/SliceExhausted/DependencyUnavailable).
        'App\Exceptions\Procurement' => [
            'root' => ['OperationFailed', 'UnknownReportFormat', 'InvalidEvalDiffPayload'],
            // Network — typed outbound control exits, без переключения legacy callers.
            // Document — typed head/storage dependency failures.
            'subareas' => ['Ai', 'Crawler', 'Document', 'Health', 'Network', 'Parsing', 'Platform', 'Prepare', 'Process', 'Proxy'],
        ],
        // , Completion Notes: три файла ядра закупки остаются в корне.
        'App\Dto\Procurement' => [
            'root' => ['ProcurementDetails', 'ProcurementSnapshotData', 'StatusResolution'],
            // `Network` — outbound plan/result/owner DTO; `Platform` — нейтральные части Definition
            // (RequestProfile, CanonicalOrigin, SourceTargetDefinitions) без кода конкретной площадки.
            // Acquisition — neutral fetch results; Document — immutable references (structure#s01).
            'subareas' => ['Acquisition', 'Ai', 'Analyzers', 'Bench', 'Crawler', 'Details', 'Document', 'Fixture', 'Gold', 'Health', 'History', 'Network', 'Parsing', 'Platform', 'Prepare', 'Process', 'Proxy'],
        ],
        // , Completion Notes: три ядровых репозитория закупки остаются в корне.
        // (2026.08.06-CRAWL-HARDEN): подобласть `Platform` заведена первым файлом
        // (`Okpd2NodeReadRepository`) — зеркалит уже существующую подобласть `App\Models\Procurement\Platform`.
        // Crawler: RunStoreRepository зеркалит существующую Models\Procurement\Crawler.
        // подобласть `Process` — writers Process ledger (structure#s01).
        // подобласть `Network` — единственный writer network authority rows.
        'App\Repositories\Procurement' => [
            'root' => ['ProcurementDetailStoreRepository', 'ProcurementReadRepository', 'ProcurementStoreRepository'],
            'subareas' => ['Ai', 'Analyzers', 'Crawler', 'Document', 'Health', 'History', 'Network', 'Parsing', 'Platform', 'Process', 'Proxy'],
        ],
    ];
}

function domainLayoutLayerDirectory(string $namespace): string
{
    return app_path(str_replace('\\', '/', substr($namespace, strlen('App\\'))));
}

test('в корне выровненных слоёв раскладки нет файлов сверх реестра root-legal классов', function (): void {
    $violations = [];

    foreach (alignedDomainLayoutLayers() as $namespace => $layer) {
        $directory = domainLayoutLayerDirectory($namespace);

        foreach (Finder::create()->files()->in($directory)->depth('== 0')->name('*.php') as $file) {
            $class = $file->getBasename('.php');

            if (! in_array($class, $layer['root'], strict: true)) {
                $violations[] = sprintf(
                    '%s\%s.php лежит в корне слоя %s, но не входит в реестр root-legal классов '.
                    '(research/04-domain-layout.md §2.1/§6): либо файл принадлежит подобласти и '.
                    'должен переехать в подкаталог, либо реестр устарел.',
                    $namespace,
                    $class,
                    $namespace
                );
            }
        }
    }

    expect($violations)->toBe([]);
});

test('namespace каждого файла выровненных слоёв совпадает с его путём', function (): void {
    $violations = [];

    foreach (alignedDomainLayoutLayers() as $namespace => $layer) {
        $directory = domainLayoutLayerDirectory($namespace);

        foreach (Finder::create()->files()->in($directory)->name('*.php') as $file) {
            $relativeDirectory = $file->getRelativePath();
            $expectedNamespace = $relativeDirectory === ''
                ? $namespace
                : $namespace.'\\'.str_replace('/', '\\', $relativeDirectory);

            $source = (string) file_get_contents($file->getRealPath());

            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $matches)) {
                $violations[] = sprintf('%s не объявляет namespace.', $file->getRealPath());

                continue;
            }

            if ($matches[1] !== $expectedNamespace) {
                $violations[] = sprintf(
                    '%s объявляет namespace %s, а по пути обязан быть %s.',
                    $file->getRealPath(),
                    $matches[1],
                    $expectedNamespace
                );
            }
        }
    }

    expect($violations)->toBe([]);
});

test('подкаталоги выровненных слоёв принадлежат утверждённому множеству подобластей', function (): void {
    $violations = [];

    foreach (alignedDomainLayoutLayers() as $namespace => $layer) {
        $directory = domainLayoutLayerDirectory($namespace);

        foreach (Finder::create()->directories()->in($directory)->depth('== 0') as $subdirectory) {
            $name = $subdirectory->getFilename();

            if (! in_array($name, $layer['subareas'], strict: true)) {
                $violations[] = sprintf(
                    'Подкаталог %s\%s не входит в утверждённое множество подобластей слоя %s '.
                    '(research/04-domain-layout.md §2.1/§6): либо это новая подобласть, требующая '.
                    'записи в реестре, либо ошибочно заведённый каталог.',
                    $namespace,
                    $name,
                    $namespace
                );
            }
        }
    }

    expect($violations)->toBe([]);
});
