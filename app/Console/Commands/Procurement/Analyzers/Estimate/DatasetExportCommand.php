<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Services\Procurement\Analyzers\Estimate\DatasetExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Экспорт `prc_ai_dataset_examples` (gold, P9.3/D15/D16) в обучающий датасет: `--format=sft` —
 * JSONL chat-format, `--format=dpo` — preference-пары. Манифест (счётчики/версии/sha) пишется
 * рядом с выходным файлом тем же именем с суффиксом `.manifest.json` — воспроизводимость
 * training-run (D15). Логика вынесена в {@see DatasetExporter} (P3.3).
 */
final class DatasetExportCommand extends Command
{
    protected $signature = 'estimate:dataset:export
        {--format=sft : sft|dpo}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--disk= : диск назначения (по умолчанию — config(crawler.raw_disk))}
        {--path= : путь внутри диска (по умолчанию — ai/dataset-exports/<format>-<timestamp>.jsonl)}';

    protected $description = 'Экспорт gold-датасета (prc_ai_dataset_examples) в SFT/DPO JSONL для дообучения локальной LLM';

    public function __construct(private readonly DatasetExporter $exporter)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        if (! in_array($format, ['sft', 'dpo'], strict: true)) {
            $this->error("Неизвестный --format={$format} (ожидается sft|dpo).");

            return self::FAILURE;
        }

        $disk = (string) ($this->option('disk') ?: config('crawler.raw_disk'));
        $path = (string) ($this->option('path') ?: sprintf('ai/dataset-exports/%s-%s.jsonl', $format, now()->format('Ymd-His')));

        [$count, $stream] = $this->exporter->export($format, (string) $this->option('task'));

        Storage::disk($disk)->put($path, $stream);
        fclose($stream);

        $manifestPath = (string) preg_replace('/\.jsonl$/', '.manifest.json', $path);
        $written = (string) Storage::disk($disk)->get($path);

        Storage::disk($disk)->put($manifestPath, json_encode([
            'format' => $format,
            'task' => (string) $this->option('task'),
            'examples' => $count,
            'generated_at' => now()->toIso8601String(),
            'sha256' => hash('sha256', $written),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $this->info("Экспортировано {$count} примеров → {$disk}:{$path} (манифест: {$manifestPath}).");

        return self::SUCCESS;
    }
}
