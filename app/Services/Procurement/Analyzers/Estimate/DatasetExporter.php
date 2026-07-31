<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Enums\Procurement\AnalysisStep;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\AnalysisReadRepository;
use App\Repositories\Procurement\DatasetExampleReadRepository;
use App\Services\Llm\PromptComposer;

/**
 * Экспорт `prc_ai_dataset_examples` (gold, P9.3/D15/D16) в обучающий датасет
 * (`estimate:dataset:export`, вынесено из `DatasetExportCommand`, P3.3):
 * - `sft` — JSONL chat-format (D16-стек: Qwen3-4B/8B QLoRA-SFT). `system` — та же композиция,
 *   что боевой `extract_rows`-вызов (`PromptComposer`, переиспользована буквально). `assistant` —
 *   `{rows, incomplete_items}` того же контракта, что `RowsChunkResponse` (§3.2). `user` —
 *   ВОСПРОИЗВОДИМАЯ ссылка на документ (document_version_id + storage_path), НЕ сам текст
 *   документа: распознавание документа — отдельный, ЕЩЁ НЕ РЕАЛИЗОВАННЫЙ этап подготовки корпуса
 *   (deferred, см. handoff P9.2/P9.3).
 * - `dpo` — preference-пары chosen(gold)/rejected(`Analysis.result` предшественника), только там,
 *   где `example->source === 'corrected'` (P9.3 ревью, MED-фикс): напрямую сравнивать `chosen`
 *   (нормализованный контракт) с `rejected` (сырой `Analysis.result`) на строковое равенство
 *   НЕЛЬЗЯ — представления структурно различаются ВСЕГДА. `source` уже хранит именно этот сигнал
 *   (`GoldExampleRecorder`: `corrected` ⟺ у прямого предшественника есть применённая коррекция).
 */
final readonly class DatasetExporter
{
    public function __construct(
        private PromptComposer $prompts,
        private DatasetExampleReadRepository $examples,
        private AnalysisReadRepository $analyses,
        private EstimateStepDictionaries $dictionaries,
    ) {}

    /**
     * @return array{0: int, 1: resource}
     */
    public function export(string $format, string $task): array
    {
        return $format === 'sft' ? $this->writeSft($task) : $this->writeDpo($task);
    }

    /**
     * @return array{0: int, 1: resource}
     */
    private function writeSft(string $task): array
    {
        $composed = $this->prompts->compose(
            EstimatePromptStep::ExtractionRows,
            [RowsChunkResponse::class, ...$this->dictionaries->forStep(EstimatePromptStep::ExtractionRows)],
        );
        $stream = fopen('php://temp', 'w+');
        $count = 0;

        foreach ($this->examples->goldExamples($task)->with('documentVersion')->cursor() as $example) {
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
    private function writeDpo(string $task): array
    {
        $stream = fopen('php://temp', 'w+');
        $count = 0;

        foreach ($this->examples->goldExamples($task)->cursor() as $example) {
            /** @var DatasetExample $example */
            $activeAnalysis = $this->analyses->activeForVersion($example->document_version_id, AnalysisStep::Extraction);

            if ($activeAnalysis === null || $activeAnalysis->parent_analysis_id === null) {
                continue;
            }

            $parent = $this->analyses->parentOf($activeAnalysis);

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
