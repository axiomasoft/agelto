<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Services\Procurement\Analyzers\Estimate\DatasetExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Экспорт `prc_ai_dataset_examples` (gold с реальным входом и `analysis_id`)
 * в обучающий датасет: `--format=sft` — JSONL chat-format, `--format=dpo` — preference-пары.
 * Манифест (счётчики/версии/sha/пропуски) пишется рядом с выходным файлом тем же именем с
 * суффиксом `.manifest.json` — воспроизводимость training-run. Логика вынесена в
 * {@see DatasetExporter}.
 */
#[Description('Экспорт gold-датасета (prc_ai_dataset_examples) в SFT/DPO JSONL для дообучения локальной LLM')]
#[Signature('estimate:dataset:export
        {--format=sft : sft|dpo}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--split=all : all|tune|holdout}
        {--source=* : ограничить значениями prc_ai_dataset_examples.source}
        {--dpo-schema=conversational : conversational|standard}
        {--disk= : диск назначения (по умолчанию — config(acquisition.raw_disk))}
        {--path= : путь внутри диска (по умолчанию — ai/dataset-exports/<format>-<timestamp>.jsonl)}')]
final class DatasetExportCommand extends Command
{
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

        $split = (string) $this->option('split');

        if (! in_array($split, ['all', 'tune', 'holdout'], strict: true)) {
            $this->error("Неизвестный --split={$split} (ожидается all|tune|holdout).");

            return self::FAILURE;
        }

        $dpoSchema = (string) $this->option('dpo-schema');

        if (! in_array($dpoSchema, ['conversational', 'standard'], strict: true)) {
            $this->error("Неизвестный --dpo-schema={$dpoSchema} (ожидается conversational|standard).");

            return self::FAILURE;
        }

        $task = (string) $this->option('task');
        /** @var list<string> $sources */
        $sources = (array) $this->option('source');
        $disk = $this->disk();
        $path = (string) ($this->option('path') ?: sprintf('ai/dataset-exports/%s-%s.jsonl', $format, now()->format('Ymd-His')));

        [$stats, $stream] = $this->exporter->export($format, $task, $split, $sources, $dpoSchema);

        Storage::disk($disk)->put($path, $stream);
        fclose($stream);

        $manifestPath = (string) preg_replace('/\.jsonl$/', '.manifest.json', $path);
        $written = (string) Storage::disk($disk)->get($path);

        Storage::disk($disk)->put($manifestPath, json_encode([
            'schema' => $format === 'sft' ? 'sft-messages' : "dpo-{$dpoSchema}",
            'exporter_version' => DatasetExporter::exporterVersion(),
            'format' => $format,
            'task' => $task,
            'split' => $split,
            'source' => $sources,
            'examples' => $stats['examples'],
            'skipped' => $stats['skipped'],
            'by_source' => $stats['by_source'],
            'by_split' => $stats['by_split'],
            'by_representation_kind' => $stats['by_representation_kind'],
            'prompt_version_set' => $stats['prompt_version_set'],
            'rules_hash' => $stats['rules_hash'],
            'plan' => '2026.07.28-EST-ARCH (D56)',
            'generated_at' => now()->toIso8601String(),
            'sha256' => hash('sha256', $written),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        foreach ($stats['warnings'] as $warning) {
            $this->warn($warning);
        }

        $this->info("Экспортировано {$stats['examples']} примеров → {$disk}:{$path} (манифест: {$manifestPath}).");

        if ($stats['skipped'] !== []) {
            $this->table(['причина', 'кол-во'], collect($stats['skipped'])->map(fn (int $n, string $reason): array => [$reason, $n])->values()->all());
        }

        return self::SUCCESS;
    }

    private function disk(): string
    {
        $disk = $this->option('disk');

        if (is_string($disk) && $disk !== '') {
            return $disk;
        }

        $configuredDisk = config('acquisition.raw_disk');

        if (! is_string($configuredDisk) || $configuredDisk === '') {
            throw new \UnexpectedValueException('acquisition.raw_disk must be a non-empty string.');
        }

        return $configuredDisk;
    }
}
