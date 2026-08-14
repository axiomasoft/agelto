<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Services\Procurement\Bench\FormatBenchRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Бенчмарк форматов входа (P6.1, D3/D48–D52): матрица `(эталон × вариант представления)` по
 * корпусу эталонов с ДЕТЕРМИНИРОВАННЫМ gold. `--dry-run` — сухой обход БЕЗ единого LLM-вызова
 * (объём и предсказанная цена детерминированы, `research/11-format-bench-method.md`); без флага —
 * боевой прогон через `ExtractionOrchestrator` (гейт OQ-3, P6.2, реальный провайдер владельца —
 * запускается ВРУЧНУЮ, в тестах — только `FakeLlmProvider`, `Implementation Rules`).
 */
#[Description('Матрица (эталон × вариант представления): объём/цена/качество/поведение по ячейке')]
#[Signature('estimate:bench:formats
        {--code=* : ограничить эталоны кодами}
        {--variant=* : ограничить варианты (значения RepresentationKind или default)}
        {--dry-run : без LLM-вызовов, только детерминированные факты и предсказание}
        {--out= : путь машинного артефакта (дефолт config(ai.fixtures.reports_dir)/bench-formats-<режим>-<дата>.json)}
        {--limit= : ограничить число эталонов}')]
final class BenchFormatsCommand extends Command
{
    public function __construct(
        private readonly FormatBenchRunner $runner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        /** @var list<string> $codes */
        $codes = (array) $this->option('code');
        /** @var list<string> $variants */
        $variants = (array) $this->option('variant');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        $artifact = $this->runner->run($codes, $variants, $dryRun, $limit);

        $mode = $dryRun ? 'dry' : 'run';
        $date = now()->format('Y-m-d');
        $outOption = $this->option('out');

        // Дефолт — НОВЫЙ корень `config('ai.fixtures.reports_dir')` (D111): раньше хардкодился
        // путь в каталог ЧУЖОГО замороженного плана, который уедет в архив (D2/D9).
        // `config('ai.fixtures.reports_dir')` уже АБСОЛЮТНЫЙ (дефолт `base_path('estimates/reports')`),
        // поэтому дефолтная ветка НЕ оборачивается `base_path()` второй раз — только явный
        // операторский `--out` остаётся репо-относительным, как раньше.
        $path = $outOption !== null
            ? base_path($outOption)
            : $this->reportsDirectory()."/bench-formats-{$mode}-{$date}.json";

        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        // Каталог отчётов создаётся при записи (Implementation Rule 10 P3.3):
        // `estimates/reports/` в репозитории не существует.
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $json);

        foreach ($artifact['rejected'] as $reason) {
            $this->warn("отклонён: {$reason}");
        }

        $this->info(sprintf(
            'Готово (%s): ячеек %d, отклонено %d. Артефакт: %s',
            $mode,
            count($artifact['cells']),
            count($artifact['rejected']),
            $path,
        ));

        return self::SUCCESS;
    }

    private function reportsDirectory(): string
    {
        $reportsDirectory = config('ai.fixtures.reports_dir');

        if (! is_string($reportsDirectory)) {
            throw new \UnexpectedValueException('ai.fixtures.reports_dir must be a string.');
        }

        return $reportsDirectory;
    }
}
