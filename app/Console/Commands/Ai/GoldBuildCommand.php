<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\FixtureStoreRepository;
use App\Services\Procurement\Gold\GoldBuilder;
use App\Services\Procurement\Gold\ScreeningGoldBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use JsonException;

/**
 * Сборка `estimates/gold/**` по реестру `prc_ai_fixtures` (P5.3, D45): для каждого эталона с
 * `gold_source=deterministic` вызывает {@see GoldBuilder} (маппер `.gsfx` — P5.1, книга — P5.2),
 * пишет `<CODE>.gold.json`, собирает сводный отчёт `research/10-gold-corpus-report.md` и, под
 * `--update-manifest`, дописывает в `estimates/manifest.json` РОВНО четыре gold-производных поля.
 * Команда — сборщик, а не редактор истины (Code Guidance): манифест остаётся SSOT корпуса (D20).
 *
 * Ветка `--screening` (P5.4, D5, §8.5) — ОТДЕЛЬНЫЙ проход по ВСЕМ эталонам корпуса (не только
 * `gold_source=deterministic`) через {@see ScreeningGoldBuilder}: пишет `<CODE>.screening.json`
 * РЯДОМ с extraction-gold, манифест НЕ трогает (screening-gold в манифест не заводится, §8.5).
 */
final class GoldBuildCommand extends Command
{
    protected $signature = 'ai:gold:build
        {--code=* : ограничить эталоны кодами}
        {--gold-dir= : куда писать (дефолт config(ai.fixtures.gold_dir))}
        {--manifest= : путь к manifest.json}
        {--update-manifest : дописать gold-производные факты в манифест}
        {--dry-run : ничего не писать, только отчёт}
        {--screening : собрать gold/<CODE>.screening.json по всему корпусу вместо extraction-gold}';

    protected $description = 'Собрать estimates/gold/** по реестру prc_ai_fixtures и обновить gold-факты манифеста';

    public function __construct(
        private readonly FixtureStoreRepository $fixtures,
        private readonly GoldBuilder $builder,
        private readonly ScreeningGoldBuilder $screeningBuilder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Книги реального корпуса (десятки листов, MERGE_HEAVY) исчерпывают дефолтный лимит
        // PHP-CLI на PhpSpreadsheet::load() — тот же приём, что `SpreadsheetStrategy` (D28)
        // применяет к дочернему процессу конвертации.
        ini_set('memory_limit', (string) config('ai.prepare.xlsx_convert_memory_limit', '512M'));

        $goldDir = (string) ($this->option('gold-dir') ?: config('ai.fixtures.gold_dir'));
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $onlyCodes */
        $onlyCodes = (array) $this->option('code');

        if ((bool) $this->option('screening')) {
            return $this->handleScreening($goldDir, $onlyCodes, $dryRun);
        }

        $manifestPath = (string) ($this->option('manifest') ?: config('ai.fixtures.manifest_path'));
        $disk = (string) config('ai.fixtures.disk');
        $updateManifest = (bool) $this->option('update-manifest');

        if (! is_file($manifestPath)) {
            $this->error("Манифест не найден: {$manifestPath}");

            return self::FAILURE;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Манифест не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($manifest) || ! is_array($manifest['fixtures'] ?? null)) {
            $this->error('Ожидается объект вида {"version": 1, "fixtures": [...]}');

            return self::FAILURE;
        }

        $fixtures = $this->fixturesInCodeOrder($onlyCodes);

        $built = 0;
        $skipped = 0;
        $downgraded = 0;
        $rejected = 0;
        $reportRows = [];
        $manifestFacts = [];

        foreach ($fixtures as $fixture) {
            $row = $this->processFixture($fixture, $goldDir, $disk, $dryRun);
            $reportRows[] = $row;

            /** @var 'built'|'skipped'|'downgraded'|'rejected' $status */
            $status = $row['status'];

            match ($status) {
                'built' => $built++,
                'skipped' => $skipped++,
                'downgraded' => $downgraded++,
                'rejected' => $rejected++,
            };

            if ($row['manifest_fact'] !== null) {
                $manifestFacts[$fixture->code] = $row['manifest_fact'];
            }
        }

        if ($updateManifest && ! $dryRun) {
            $manifest['fixtures'] = $this->applyManifestFacts((array) $manifest['fixtures'], $manifestFacts);

            file_put_contents(
                $manifestPath,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        if (! $dryRun) {
            $this->writeReport($reportRows);
        }

        $this->info("Готово: построено {$built} · пропущено {$skipped} · понижено {$downgraded} · отклонено {$rejected}.");

        return $rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $onlyCodes
     */
    private function handleScreening(string $goldDir, array $onlyCodes, bool $dryRun): int
    {
        $fixtures = $this->fixturesInCodeOrder($onlyCodes);

        $built = 0;
        $rejected = 0;

        foreach ($fixtures as $fixture) {
            $result = $this->screeningBuilder->build($fixture);

            if ($result['action'] === 'rejected') {
                $rejected++;
                $this->error("{$fixture->code}: {$result['reason']}");

                continue;
            }

            $built++;

            if (! $dryRun) {
                file_put_contents(
                    "{$goldDir}/{$fixture->code}.screening.json",
                    // `JSON_PRESERVE_ZERO_FRACTION` — `confidence` эталона всегда `1.0` (float,
                    // §8.5), без флага PHP печатает целое `1` и `ScreeningResult::
                    // validateAndCreate()` на повторном чтении расходится по типу.
                    json_encode($result['screening'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n",
                );
            }
        }

        $this->info("Готово: построено {$built} · отклонено {$rejected}.");

        return $rejected > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $onlyCodes
     * @return Collection<int, Fixture>
     */
    private function fixturesInCodeOrder(array $onlyCodes): Collection
    {
        $fixtures = $this->fixtures->all()
            ->sortBy(fn (Fixture $fixture): string => $fixture->code)
            ->values();

        if ($onlyCodes !== []) {
            $fixtures = $fixtures->filter(fn (Fixture $fixture): bool => in_array($fixture->code, $onlyCodes, strict: true))->values();
        }

        return $fixtures;
    }

    /**
     * @return array{
     *     code: string, source_format: string, status: string, reason: string|null,
     *     rows: int|null, estimates: int|null, arithmetic_ratio: float|null,
     *     unmapped: array<string, array{count: int, has_carrier: bool}>,
     *     manifest_fact: array{gold_path: string|null, expected_rows: int|null, expected_estimates: int|null, gold_source: string}|null,
     * }
     */
    private function processFixture(Fixture $fixture, string $goldDir, string $disk, bool $dryRun): array
    {
        if ($fixture->class === FixtureClass::Negative || $fixture->gold_source === GoldSource::None) {
            return $this->reportRow($fixture, 'skipped', 'негатив, extraction-gold не предусмотрен');
        }

        if ($fixture->gold_source === GoldSource::LlmAssisted) {
            return $this->reportRow($fixture, 'skipped', 'gold строится в P8');
        }

        // gold_source === Deterministic отсюда.
        $sourcePath = Storage::disk($disk)->path($fixture->storage_path);

        if (! Storage::disk($disk)->exists($fixture->storage_path)) {
            return $this->reportRow($fixture, 'rejected', "файл эталона не найден на диске: {$fixture->storage_path}");
        }

        $profilePath = $goldDir.'/'.$fixture->code.'.profile.json';
        $result = $this->builder->build($fixture->source_format, $sourcePath, $profilePath);

        return match ($result['action']) {
            'built' => $this->handleBuilt($fixture, $goldDir, $dryRun, $result),
            'downgraded' => $this->handleDowngraded($fixture, $result),
            'rejected' => $this->reportRow($fixture, 'rejected', (string) $result['reason']),
        };
    }

    /**
     * @param  array{gold: array<string, mixed>, rows: int, estimates: int, arithmetic_ratio: float|null,
     *     unmapped: array<string, array{count: int, has_carrier: bool}>}  $result
     */
    private function handleBuilt(Fixture $fixture, string $goldDir, bool $dryRun, array $result): array
    {
        $goldPath = "{$goldDir}/{$fixture->code}.gold.json";
        // `ai:fixtures:verify` (P2.4) резолвит `gold_path` через `base_path()` буквально —
        // путь обязан быть репозиторно-корневым, а не относительным к `gold_dir`.
        $relativeGoldPath = "estimates/gold/{$fixture->code}.gold.json";

        if (! $dryRun) {
            file_put_contents(
                $goldPath,
                json_encode($result['gold'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            );
        }

        $row = $this->reportRow($fixture, 'built', null, $result);
        $row['manifest_fact'] = [
            'gold_path' => $relativeGoldPath,
            'expected_rows' => $result['rows'],
            'expected_estimates' => $result['estimates'],
            'gold_source' => GoldSource::Deterministic->value,
        ];

        return $row;
    }

    /**
     * @param  array{estimates: int, reason: string}  $result
     */
    private function handleDowngraded(Fixture $fixture, array $result): array
    {
        $row = $this->reportRow($fixture, 'downgraded', $result['reason']);
        $row['manifest_fact'] = [
            'gold_path' => null,
            'expected_rows' => null,
            'expected_estimates' => $fixture->expected_estimates,
            'gold_source' => GoldSource::LlmAssisted->value,
        ];

        return $row;
    }

    /**
     * @param  array{rows?: int|null, estimates?: int|null, arithmetic_ratio?: float|null,
     *     unmapped?: array<string, array{count: int, has_carrier: bool}>}|null  $result
     */
    private function reportRow(Fixture $fixture, string $status, ?string $reason, ?array $result = null): array
    {
        return [
            'code' => $fixture->code,
            'source_format' => $fixture->source_format,
            'status' => $status,
            'reason' => $reason,
            'rows' => $result['rows'] ?? null,
            'estimates' => $result['estimates'] ?? null,
            'arithmetic_ratio' => $result['arithmetic_ratio'] ?? null,
            'unmapped' => $result['unmapped'] ?? [],
            'manifest_fact' => null,
        ];
    }

    /**
     * @param  list<mixed>  $fixturesJson
     * @param  array<string, array{gold_path: string|null, expected_rows: int|null, expected_estimates: int|null, gold_source: string}>  $facts
     * @return list<mixed>
     */
    private function applyManifestFacts(array $fixturesJson, array $facts): array
    {
        return array_map(function (mixed $entry) use ($facts): mixed {
            if (! is_array($entry) || ! isset($entry['code']) || ! isset($facts[$entry['code']])) {
                return $entry;
            }

            $fact = $facts[$entry['code']];
            $entry['gold_path'] = $fact['gold_path'];
            $entry['expected_rows'] = $fact['expected_rows'];
            $entry['expected_estimates'] = $fact['expected_estimates'];
            $entry['gold_source'] = $fact['gold_source'];

            return $entry;
        }, $fixturesJson);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeReport(array $rows): void
    {
        $lines = [];
        $lines[] = '# 10 — Сводный отчёт корпуса gold (P5.3)';
        $lines[] = '';
        $lines[] = 'Сгенерировано `ai:gold:build` (D45) по реестру `prc_ai_fixtures`. Не редактируется руками —';
        $lines[] = 'перегенерируется командой при следующем прогоне.';
        $lines[] = '';
        $lines[] = '| код | формат | источник gold | смет | строк | итог документа | доля арифм. сходимости | неотображённые элементы | вердикт |';
        $lines[] = '|:--|:--|:--|:--|:--|:--|:--|:--|:--|';

        $downgrades = [];
        $hitl = [];

        foreach ($rows as $row) {
            /** @var 'built'|'skipped'|'downgraded'|'rejected' $status */
            $status = $row['status'];

            $verdict = match ($status) {
                'built' => 'pass by profile',
                'downgraded' => 'downgrade',
                'skipped' => 'skip',
                'rejected' => 'REJECTED',
            };

            $goldSource = match ($status) {
                'built' => 'deterministic',
                'downgraded' => 'llm_assisted (было deterministic)',
                'skipped' => 'llm_assisted/none',
                'rejected' => '—',
            };

            $arithmetic = $row['arithmetic_ratio'] === null ? 'n/a' : sprintf('%.4f', $row['arithmetic_ratio']);
            $unmapped = $row['unmapped'] === [] ? '—' : implode(', ', array_map(
                static fn (string $key, array $entry): string => "{$key}×{$entry['count']}",
                array_keys($row['unmapped']),
                array_values($row['unmapped']),
            ));

            $lines[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                $row['code'],
                $row['source_format'],
                $goldSource,
                $row['estimates'] ?? '—',
                $row['rows'] ?? '—',
                'n/a',
                $arithmetic,
                $unmapped,
                $verdict,
            );

            if ($row['status'] === 'downgraded') {
                $downgrades[] = "- **{$row['code']}** — {$row['reason']}";
                $hitl[] = "- **{$row['code']}** ждёт HITL-построения gold в P8 (D2).";
            }
        }

        $lines[] = '';
        $lines[] = '## Понижения `gold_source`';
        $lines[] = '';
        $lines[] = $downgrades === [] ? 'Нет понижений в этом прогоне.' : implode("\n", $downgrades);
        $lines[] = '';
        $lines[] = '## Что это значит для P8';
        $lines[] = '';
        $lines[] = $hitl === [] ? 'Нет эталонов, ожидающих HITL по итогам этого прогона.' : implode("\n", $hitl);
        $lines[] = '';

        file_put_contents(
            base_path('plans/2026.07.26-EST-TRAIN/research/10-gold-corpus-report.md'),
            implode("\n", $lines),
        );
    }
}
