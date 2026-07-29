<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\FixtureStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport;
use Illuminate\Console\Command;
use JsonException;

/**
 * Диагностическая обёртка над {@see EstimateDiffReport} (P7.3, D62): по коду эталона резолвит
 * gold-файл корпуса (`estimates/gold/<CODE>.gold.json`, тот же путь, что `ai:gold:build`) и
 * АКТИВНЫЙ `Analysis(step=extraction)` рабочей копии эталона (`fixture:<CODE>` — соглашение
 * `ai:gold:import`), либо берёт анализ напрямую по `--analysis` и резолвит код эталона обратным
 * ходом через `external_id` документа. НИ ОДНОГО LLM-вызова не делает — читает уже полученный
 * `analysis.result`, не запускает оркестратор (в отличие от `ai:eval:run`).
 */
final class EvalDiffCommand extends Command
{
    protected $signature = 'ai:eval:diff
        {--code= : код эталона}
        {--analysis= : id анализа вместо кода}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}
        {--limit-rows=200 : ограничение списка построчных расхождений (0 — без лимита)}';

    protected $description = 'Детерминированный diff-отчёт «gold vs предсказание» по эталону — без единого LLM-вызова';

    public function __construct(
        private readonly EstimateDiffReport $diffReport,
        private readonly FixtureStoreRepository $fixtures,
        private readonly DocumentStoreRepository $documents,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $code = $this->option('code') !== null ? (string) $this->option('code') : null;
        $analysisId = $this->option('analysis') !== null ? (int) $this->option('analysis') : null;
        $format = (string) $this->option('format');
        $limitRowsOption = (int) $this->option('limit-rows');
        $limitRows = $limitRowsOption > 0 ? $limitRowsOption : null;

        if (! in_array($format, ['json', 'md'], true)) {
            $this->error("Неизвестный --format={$format} (ожидается json|md).");

            return self::FAILURE;
        }

        if ($code === null && $analysisId === null) {
            $this->error('Нужен --code=<эталон> или --analysis=<id>.');

            return self::FAILURE;
        }

        $analysis = $analysisId !== null
            ? Analysis::query()->where('step', AnalysisStep::Extraction)->find($analysisId)
            : $this->latestExtractionAnalysis((string) $code);

        if ($analysis === null) {
            $this->error($analysisId !== null
                ? "Analysis #{$analysisId} (step=extraction) не найден."
                : "Активный Analysis(step=extraction) для эталона {$code} не найден — прогони ai:eval:run.");

            return self::FAILURE;
        }

        $code ??= $this->fixtureCodeFor($analysis);

        if ($code === null) {
            $this->error("Analysis #{$analysis->id} не связан с эталоном (external_id документа не по конвенции fixture:<CODE>).");

            return self::FAILURE;
        }

        // `ai.fixtures.gold_dir` уже несёт `base_path()` (config/ai.php) — повторный base_path()
        // задвоил бы путь.
        $goldPath = ((string) config('ai.fixtures.gold_dir'))."/{$code}.gold.json";

        if (! is_file($goldPath)) {
            $this->error("Gold-файл не найден: {$goldPath}");

            return self::FAILURE;
        }

        try {
            $gold = json_decode((string) file_get_contents($goldPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Gold-файл не распарсен: {$e->getMessage()}");

            return self::FAILURE;
        }

        $goldEstimates = (array) ($gold['estimates'] ?? []);
        $predictedEstimates = (array) ($analysis->result['estimates'] ?? []);

        $report = $this->diffReport->build($goldEstimates, $predictedEstimates, $limitRows);
        $rendered = $format === 'json' ? $this->renderJson($report->toArray()) : $this->renderMarkdown($code, $analysis, $report->toArray());

        $out = $this->option('out');

        if ($out !== null) {
            file_put_contents(base_path((string) $out), $rendered);
        }

        $this->line($rendered);
        $this->info("Вердикт: {$report->verdict}");

        return self::SUCCESS;
    }

    private function latestExtractionAnalysis(string $code): ?Analysis
    {
        $fixture = $this->fixtures->findByCode($code);

        if ($fixture === null) {
            return null;
        }

        $document = $fixture->document;

        if ($document === null) {
            return null;
        }

        $version = $this->documents->findVersionByHash($document->id, $fixture->sha256);

        if ($version === null) {
            return null;
        }

        return Analysis::query()
            ->active()
            ->where('document_version_id', $version->id)
            ->where('step', AnalysisStep::Extraction)
            ->first();
    }

    private function fixtureCodeFor(Analysis $analysis): ?string
    {
        $externalId = $analysis->documentVersion?->document?->external_id;

        if ($externalId === null || ! str_starts_with($externalId, 'fixture:')) {
            return null;
        }

        $code = substr($externalId, strlen('fixture:'));

        return Fixture::query()->where('code', $code)->exists() ? $code : null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderJson(array $report): string
    {
        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderMarkdown(string $code, Analysis $analysis, array $report): string
    {
        $lines = [];
        $lines[] = "# Diff-отчёт: {$code} (Analysis #{$analysis->id})";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$report['verdict']}";
        $lines[] = '';
        $lines[] = sprintf('Показано строчных расхождений %d из %d.', $report['truncated']['shown'], $report['truncated']['total']);
        $lines[] = '';

        foreach (['rows' => 'Строки', 'passport' => 'Паспорт', 'totals' => 'Итоги'] as $key => $title) {
            /** @var list<array<string, mixed>> $entries */
            $entries = $report[$key];
            $lines[] = "## {$title} ({$this->count($entries)})";
            $lines[] = '';

            if ($entries === []) {
                $lines[] = 'Расхождений нет.';
                $lines[] = '';

                continue;
            }

            $lines[] = '| смета | строка | вид | поле | gold | предсказание |';
            $lines[] = '|--:|--:|:--|:--|:--|:--|';

            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '| %d | %s | %s | %s | %s | %s |',
                    $entry['estimate_index'],
                    $entry['row_index'] ?? '—',
                    $entry['kind'],
                    $entry['field'] ?? '—',
                    $this->scalar($entry['gold']),
                    $this->scalar($entry['predicted']),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<mixed>  $entries
     */
    private function count(array $entries): int
    {
        return count($entries);
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        return (string) $value;
    }
}
