<?php

declare(strict_types=1);

namespace App\Services\Procurement\Gold;

use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Сборка `estimates/gold/**` по реестру `prc_ai_fixtures` (P5.3, D45, вынесено из
 * `GoldBuildCommand`, `root/service-layer-map.md` §3/§4 «неоднозначность 1»): для каждого
 * эталона с `gold_source=deterministic` вызывает {@see GoldBuilder}, собирает сводный отчёт и
 * (под `--update-manifest`) манифест-факты. Живёт в `App\Services\Procurement\Gold\`, а НЕ в
 * каталоге анализатора: единственный исполнитель — `Gold\GoldBuilder`, каталоги сервисов
 * `Gold`/`Bench`/`Prepare` эта фаза сознательно не переклассифицирует (D31).
 */
final readonly class GoldCorpusBuilder
{
    public function __construct(private GoldBuilder $builder) {}

    /**
     * @param  Collection<int, Fixture>  $fixtures  уже отобранные/упорядоченные по коду
     * @param  list<mixed>  $manifestFixtures  сырой массив `fixtures` манифеста
     * @return array{
     *     rows: list<array<string, mixed>>,
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

        return [
            'rows' => $reportRows,
            'manifestFixtures' => $this->applyManifestFacts($manifestFixtures, $manifestFacts),
            'counts' => ['built' => $built, 'skipped' => $skipped, 'downgraded' => $downgraded, 'rejected' => $rejected],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function renderReport(array $rows): string
    {
        $lines = [];
        $lines[] = '# 10 — Сводный отчёт корпуса gold (P5.3)';
        $lines[] = '';
        $lines[] = 'Сгенерировано `estimate:gold:build` (D45) по реестру `prc_ai_fixtures`. Не редактируется руками —';
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

        return implode("\n", $lines);
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
     * @param  array{estimates: int, reason: string}  $result
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
}
