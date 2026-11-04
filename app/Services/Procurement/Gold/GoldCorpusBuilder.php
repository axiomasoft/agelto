<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Сборка estimates/gold/** по prc_ai_fixtures: deterministic эталоны обрабатывает
 * GoldBuilder. Сервис собирает сводный отчёт и факты для обновления манифеста;
 * console command отвечает за представление результата.
 */
/**
 * @phpstan-type ManifestFact array{gold_path: string|null, expected_rows: int|null, expected_estimates: int|null, gold_source: string}
 * @phpstan-type UnmappedReport array<string, array{count: int, has_carrier: bool}>
 * @phpstan-type CorpusRow array{code: string, source_format: string, status: 'built'|'skipped'|'downgraded'|'rejected', reason: string|null, rows: int|null, estimates: int|null, arithmetic_ratio: float|null, unmapped: UnmappedReport, manifest_fact: ManifestFact|null}
 */
final readonly class GoldCorpusBuilder
{
    public function __construct(private GoldBuilder $builder) {}

    /**
     * @param  Collection<int, Fixture>  $fixtures  уже отобранные/упорядоченные по коду
     * @param  list<mixed>  $manifestFixtures  сырой массив `fixtures` манифеста
     * @return array{
     *     rows: list<CorpusRow>,
     *     manifestFixtures: list<mixed>,
     *     counts: array{built: int, skipped: int, downgraded: int, rejected: int},
     * }
     */
    public function build(Collection $fixtures, string $goldDir, string $disk, bool $dryRun, array $manifestFixtures): array
    {
        $built = 0;
        $skipped = 0;
        $downgraded = 0;
        $rejected = 0;
        /** @var list<CorpusRow> $reportRows */
        $reportRows = [];
        /** @var array<string, ManifestFact> $manifestFacts */
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

        return [
            'rows' => $reportRows,
            'manifestFixtures' => $this->applyManifestFacts($manifestFixtures, $manifestFacts),
            'counts' => ['built' => $built, 'skipped' => $skipped, 'downgraded' => $downgraded, 'rejected' => $rejected],
        ];
    }

    /**
     * @param  list<CorpusRow>  $rows
     */
    public function renderReport(array $rows): string
    {
        $lines = [];
        $lines[] = '# Сводный отчёт корпуса gold';
        $lines[] = '';
        $lines[] = 'Сгенерировано `estimate:gold:build` по реестру `prc_ai_fixtures`. Не редактируется руками —';
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
                $hitl[] = "- **{$row['code']}** ожидает ручной подготовки gold.";
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

        return implode("\n", $lines);
    }

    /**
     * @return CorpusRow
     */
    private function processFixture(Fixture $fixture, string $goldDir, string $disk, bool $dryRun): array
    {
        if ($fixture->profileClass() === FixtureClass::Negative || $fixture->profileGoldSource() === GoldSource::None) {
            return $this->reportRow($fixture, 'skipped', 'негатив, extraction-gold не предусмотрен');
        }

        if ($fixture->profileGoldSource() === GoldSource::LlmAssisted) {
            return $this->reportRow($fixture, 'skipped', 'gold строится в P8');
        }

        // gold_source === Deterministic отсюда.
        $sourcePath = Storage::disk($disk)->path($fixture->storage_path);

        if (! Storage::disk($disk)->exists($fixture->storage_path)) {
            return $this->reportRow($fixture, 'rejected', "файл эталона не найден на диске: {$fixture->storage_path}");
        }

        $profilePath = $goldDir.'/'.$fixture->code.'.profile.json';
        $result = $this->builder->build($fixture->source_format, $sourcePath, $profilePath);

        if ($result['action'] === 'built') {
            return $this->handleBuilt($fixture, $goldDir, $dryRun, $result);
        }

        if ($result['action'] === 'downgraded') {
            return $this->handleDowngraded($fixture, $result);
        }

        return $this->reportRow($fixture, 'rejected', $result['reason']);
    }

    /**
     * @param  array{action: 'built', gold: array{estimates: list<array<string, mixed>>}, rows: int, estimates: int, arithmetic_ratio: float|null, unmapped: UnmappedReport, reason: null}  $result
     * @return CorpusRow
     */
    private function handleBuilt(Fixture $fixture, string $goldDir, bool $dryRun, array $result): array
    {
        $goldPath = "{$goldDir}/{$fixture->code}.gold.json";
        // `estimate:fixtures:verify` резолвит `gold_path` через `base_path()` буквально — путь
        // обязан быть репозиторно-корневым, а не относительным к `gold_dir`.
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
     * @param  array{action: 'downgraded', gold: null, rows: null, estimates: int, arithmetic_ratio: null, unmapped: UnmappedReport, reason: string}  $result
     * @return CorpusRow
     */
    private function handleDowngraded(Fixture $fixture, array $result): array
    {
        $row = $this->reportRow($fixture, 'downgraded', $result['reason']);
        $row['manifest_fact'] = [
            'gold_path' => null,
            'expected_rows' => null,
            'expected_estimates' => $fixture->profileExpectedEstimates(),
            'gold_source' => GoldSource::LlmAssisted->value,
        ];

        return $row;
    }

    /**
     * @param  'built'|'skipped'|'downgraded'|'rejected'  $status
     * @param  array{rows?: int|null, estimates?: int|null, arithmetic_ratio?: float|null, unmapped?: UnmappedReport}|null  $result
     * @return CorpusRow
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
     * @param  array<string, ManifestFact>  $facts
     * @return list<mixed>
     */
    private function applyManifestFacts(array $fixturesJson, array $facts): array
    {
        return array_map(function (mixed $entry) use ($facts): mixed {
            if (! is_array($entry) || ! isset($entry['code']) || ! is_string($entry['code']) || ! isset($facts[$entry['code']])) {
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
}
