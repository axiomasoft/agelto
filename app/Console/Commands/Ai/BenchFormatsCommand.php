<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Services\Procurement\Bench\FormatBenchRunner;
use Illuminate\Console\Command;

/**
 * Бенчмарк форматов входа (P6.1, D3/D48–D52): матрица `(эталон × вариант представления)` по
 * корпусу эталонов с ДЕТЕРМИНИРОВАННЫМ gold. `--dry-run` — сухой обход БЕЗ единого LLM-вызова
 * (объём и предсказанная цена детерминированы, `research/11-format-bench-method.md`); без флага —
 * боевой прогон через `ExtractionOrchestrator` (гейт OQ-3, P6.2, реальный провайдер владельца —
 * запускается ВРУЧНУЮ, в тестах — только `FakeLlmProvider`, `Implementation Rules`).
 */
final class BenchFormatsCommand extends Command
{
    protected $signature = 'ai:bench:formats
        {--code=* : ограничить эталоны кодами}
        {--variant=* : ограничить варианты (значения RepresentationKind или default)}
        {--dry-run : без LLM-вызовов, только детерминированные факты и предсказание}
        {--out= : путь машинного артефакта (дефолт plans/2026.07.26-EST-TRAIN/artifacts/bench-formats-<режим>-<дата>.json)}
        {--limit= : ограничить число эталонов}';

    protected $description = 'Матрица (эталон × вариант представления): объём/цена/качество/поведение по ячейке';

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
        $out = (string) ($this->option('out') ?: "plans/2026.07.26-EST-TRAIN/artifacts/bench-formats-{$mode}-{$date}.json");

        $json = json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        // Артефакт плана — путь РЕПО-относительный (`plans/<ID>/artifacts/**`), не диск хранилища
        // документов (тот же приём, что `GoldBuildCommand` для `research/10-gold-corpus-report.md`).
        file_put_contents(base_path($out), $json);

        foreach ($artifact['rejected'] as $reason) {
            $this->warn("отклонён: {$reason}");
        }

        $this->info(sprintf(
            'Готово (%s): ячеек %d, отклонено %d. Артефакт: %s',
            $mode,
            count($artifact['cells']),
            count($artifact['rejected']),
            $out,
        ));

        return self::SUCCESS;
    }
}
