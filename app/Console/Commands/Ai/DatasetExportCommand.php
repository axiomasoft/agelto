<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Dto\Procurement\Ai\Estimate\RowsChunkResponse;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\PromptStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Llm\PromptComposer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Экспорт `prc_ai_dataset_examples` (gold, P9.3/D15/D16) в обучающий датасет:
 * - `--format=sft` — JSONL chat-format (D16-стек: Qwen3-4B/8B QLoRA-SFT). `system` — та же
 *   композиция, что боевой `extract_rows`-вызов (`PromptComposer`, переиспользована буквально,
 *   Code Guidance) — модель учится на боевых промтах. `assistant` — `{rows, incomplete_items}`
 *   того же контракта, что `RowsChunkResponse` (§3.2). `user` — ВОСПРОИЗВОДИМАЯ ссылка на
 *   документ (document_version_id + storage_path), НЕ сам текст документа: распознавание
 *   документа (OCR/vision-транскрипция, research/G §3 «VLM без дообучения») — отдельный,
 *   ЕЩЁ НЕ РЕАЛИЗОВАННЫЙ этап подготовки корпуса (deferred, см. handoff P9.2/P9.3) — реальный
 *   training-раннер подставляет распознанный текст сам перед обучением.
 * - `--format=dpo` — preference-пары chosen(gold)/rejected(`Analysis.result` предшественника),
 *   только там, где `example->source === 'corrected'` (P9.3 ревью, MED-фикс): напрямую сравнивать
 *   `chosen` (нормализованный `EstimateToContractMapper`-контракт — decimal:6, синтезированные
 *   заголовки, переприсвоенный `row_index`) с `rejected` (сырой `Analysis.result`) на
 *   строковое равенство НЕЛЬЗЯ — представления структурно различаются ВСЕГДА, даже когда оператор
 *   одобрил сырой вывод дословно, что делало старый guard мёртвым кодом (ложные DPO-пары для
 *   verbatim-одобрений). `source` уже хранит именно этот сигнал (`GoldExampleRecorder`: `corrected`
 *   ⟺ у прямого предшественника есть применённая коррекция), поэтому фильтруем по нему, а не по
 *   сравнению JSON. Известное ограничение: пара строится через АКТИВНЫЙ анализ документа
 *   (`document_version_id`+`step=extraction`) — для СТАРЫХ версий gold (после нескольких re-run)
 *   исходный анализ-предшественник уже не резолвится корректно без сохранённого `analysis_id`
 *   (`prc_ai_dataset_examples` его не хранит, докблок `Analysis`); при НЕСКОЛЬКИХ gold-версиях
 *   одной `document_version_id` цикл резолвит один и тот же активный анализ/родителя для КАЖДОЙ
 *   версии — пары для старых версий могут не соответствовать своему реальному предшественнику.
 *   Оба ограничения требуют хранить `analysis_id` в `prc_ai_dataset_examples` (схемная правка) —
 *   вне Scope Included P9.3 (только recorder+export-команда+маппер), зафиксировано как
 *   Pending Work P9.3, не чинится этим item'ом.
 *
 * Манифест (счётчики/версии/sha) пишется рядом с выходным файлом тем же именем с суффиксом
 * `.manifest.json` — воспроизводимость training-run (D15).
 */
final class DatasetExportCommand extends Command
{
    protected $signature = 'ai:dataset:export
        {--format=sft : sft|dpo}
        {--task=extraction : значение prc_ai_dataset_examples.task}
        {--disk= : диск назначения (по умолчанию — config(crawler.raw_disk))}
        {--path= : путь внутри диска (по умолчанию — ai/dataset-exports/<format>-<timestamp>.jsonl)}';

    protected $description = 'Экспорт gold-датасета (prc_ai_dataset_examples) в SFT/DPO JSONL для дообучения локальной LLM';

    public function __construct(private readonly PromptComposer $prompts)
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

        [$count, $stream] = $format === 'sft' ? $this->writeSft() : $this->writeDpo();

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

    /**
     * @return array{0: int, 1: resource}
     */
    private function writeSft(): array
    {
        $composed = $this->prompts->compose(PromptStep::ExtractionRows, [RowsChunkResponse::class]);
        $stream = fopen('php://temp', 'w+');
        $count = 0;

        foreach ($this->goldExamples()->with('documentVersion')->cursor() as $example) {
            /** @var DocumentVersion|null $version */
            $version = $example->documentVersion;
            $estimates = (array) ($example->gold?->get('estimates') ?? []);

            foreach ($estimates as $estimate) {
                $line = [
                    'messages' => [
                        ['role' => 'system', 'content' => $composed->systemPart],
                        ['role' => 'user', 'content' => $this->userTurn($version, (array) $estimate)],
                        ['role' => 'assistant', 'content' => json_encode([
                            'rows' => $estimate['rows'] ?? [],
                            'incomplete_items' => [],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                    ],
                ];

                fwrite($stream, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $count++;
            }
        }

        rewind($stream);

        return [$count, $stream];
    }

    /**
     * @return array{0: int, 1: resource}
     */
    private function writeDpo(): array
    {
        $stream = fopen('php://temp', 'w+');
        $count = 0;

        foreach ($this->goldExamples()->cursor() as $example) {
            $activeAnalysis = Analysis::query()->active()
                ->where('document_version_id', $example->document_version_id)
                ->where('step', AnalysisStep::Extraction)
                ->first();

            if ($activeAnalysis === null || $activeAnalysis->parent_analysis_id === null) {
                continue;
            }

            $parent = Analysis::query()->find($activeAnalysis->parent_analysis_id);

            if ($parent === null || $parent->result === null) {
                continue;
            }

            if ($example->source !== 'corrected') {
                // Оператор одобрил сырой вывод без правок (source=llm) — chosen/rejected
                // структурно различаются ВСЕГДА (нормализация маппера vs сырой result), поэтому
                // единственный надёжный сигнал «пары не было» — флаг source, не JSON-сравнение.
                continue;
            }

            $chosen = json_encode($example->gold?->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $rejected = json_encode($parent->result->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            fwrite($stream, json_encode([
                'prompt' => "document_version_id={$example->document_version_id}",
                'chosen' => $chosen,
                'rejected' => $rejected,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $count++;
        }

        rewind($stream);

        return [$count, $stream];
    }

    /**
     * @return Builder<DatasetExample>
     */
    private function goldExamples(): Builder
    {
        return DatasetExample::query()->where('task', (string) $this->option('task'));
    }

    /**
     * @param  array<string, mixed>  $estimate
     */
    private function userTurn(?DocumentVersion $version, array $estimate): string
    {
        $reference = $version === null
            ? 'документ недоступен (document_version удалена)'
            : "document_version_id={$version->id}, файл «{$version->filename}» (storage_path={$version->storage_path})";

        $number = $estimate['estimate_number'] ?? null;
        $label = $number !== null ? " №{$number}" : '';

        return "Извлеки все строки сметы{$label} из документа: {$reference}. "
            .'ПРИМЕЧАНИЕ: текст документа в этом экспорте НЕ подставлен — распознавание документа '
            .'(OCR/vision-транскрипция) выполняется отдельным, ещё не реализованным этапом подготовки корпуса.';
    }
}
