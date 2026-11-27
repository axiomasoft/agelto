<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Eval;

use App\Dto\Llm\Prompt\ComposedPrompt;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\DatasetExampleReadRepository;
use RuntimeException;

/**
 * Экспорт `prc_ai_dataset_examples` (gold) в обучающий датасет (`estimate:dataset:export`):
 * - `sft` — JSONL chat-format. `system`/`user` строятся {@see DatasetInputBuilder} (ЕДИНЫЙ
 *   строитель входа для обоих форматов) — `user` несёт ФАКТИЧЕСКИЙ текст представления документа
 *   (`prc_document_representations`), а не ссылку. `assistant` — `{rows, incomplete_items}`
 *   контракта шага строк (§3.2 спеки).
 * - `dpo` — preference-пары `chosen`(gold-снапшот)/`rejected`(`Analysis.result` предшественника):
 *   предшественник резолвится через СОХРАНЁННЫЙ `analysis_id` записи — резолв через активный
 *   анализ версии документа резолвил бы ОДИН И ТОТ ЖЕ предшественник для каждой gold-версии,
 *   поэтому такой резолв недопустим.
 *
 * Основание доверия (запись экспортируема ⟺ `source=deterministic` ИЛИ непустой `verified_at`) и
 * счётчики пропусков — {@see DatasetExampleReadRepository}. Пропуск ВСЕГДА считается и печатается
 * с причиной (`skipped`, манифест) — молчаливого исключения нет.
 */
final readonly class DatasetExporter
{
    private const int EXPORTER_VERSION = 2;

    public function __construct(
        private DatasetExampleReadRepository $examples,
        private AnalysisReadRepository $analyses,
        private DatasetInputBuilder $inputBuilder,
        private EvalSplitPlanner $splitPlanner,
    ) {}

    /**
     * @param  list<string>  $sources
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    public function export(string $format, string $task, string $split, array $sources, string $dpoSchema): array
    {
        [$holdoutCodes, $warnings] = $this->loadHoldoutCodes($split);

        return $format === 'sft'
            ? $this->writeSft(task: $task, split: $split, sources: $sources, holdoutCodes: $holdoutCodes, warnings: $warnings)
            : $this->writeDpo(task: $task, split: $split, sources: $sources, dpoSchema: $dpoSchema, holdoutCodes: $holdoutCodes, warnings: $warnings);
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>|null  $holdoutCodes
     * @param  list<string>  $warnings
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function writeSft(string $task, string $split, array $sources, ?array $holdoutCodes, array $warnings): array
    {
        return $this->runExport(
            initialSkipped: ['not_trusted' => $this->examples->countNotTrusted($task, $sources)],
            warnings: $warnings,
            populate: function (ExportWriter $writer, ComposedPrompt $composed) use ($task, $split, $sources, $holdoutCodes): void {
                foreach ($this->examples->exportableSft($task, $sources) as $example) {
                    /** @var DatasetExample $example */
                    $context = $this->resolveExampleContext($example, $split, $holdoutCodes, $writer);

                    if ($context === null) {
                        continue;
                    }

                    [$rowSplit, $repKind, $text] = $context;

                    foreach ($this->goldEstimates($example) as $estimate) {
                        $ordinal = $this->nullableOrdinal($estimate['ordinal'] ?? null);
                        $estimateNumber = $this->nullableEstimateNumber($estimate['estimate_number'] ?? null);
                        $rows = $estimate['rows'] ?? [];

                        if (! is_array($rows)) {
                            throw new RuntimeException('Gold-смета содержит невалидный список строк.');
                        }

                        $line = [
                            'messages' => [
                                ['role' => 'system', 'content' => $composed->systemPart],
                                ['role' => 'user', 'content' => $this->inputBuilder->userTurn($text, $ordinal, $estimateNumber)],
                                ['role' => 'assistant', 'content' => json_encode([
                                    'rows' => $rows,
                                    'incomplete_items' => [],
                                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                            ],
                            'meta' => $this->rowMeta($example, $rowSplit, $repKind),
                        ];

                        $writer->append($line, $example->source, $rowSplit, $repKind);
                    }
                }
            },
        );
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>|null  $holdoutCodes
     * @param  list<string>  $warnings
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function writeDpo(string $task, string $split, array $sources, string $dpoSchema, ?array $holdoutCodes, array $warnings): array
    {
        return $this->runExport(
            initialSkipped: [
                'not_trusted' => $this->examples->countUntrustedCorrected($task, $sources),
                'no_analysis_id' => $this->examples->countMissingAnalysisId($task, $sources),
            ],
            warnings: $warnings,
            populate: function (ExportWriter $writer, ComposedPrompt $composed) use ($task, $split, $sources, $dpoSchema, $holdoutCodes): void {
                foreach ($this->examples->exportableDpo($task, $sources) as $example) {
                    /** @var DatasetExample $example */
                    /** @var Analysis|null $analysis */
                    $analysis = $example->analysis;

                    if ($analysis === null || $analysis->parent_analysis_id === null) {
                        $writer->skip('no_parent_analysis');

                        continue;
                    }

                    $parent = $this->analyses->parentOf($analysis);

                    if ($parent === null || $parent->result === null) {
                        $writer->skip('parent_result_empty');

                        continue;
                    }

                    $context = $this->resolveExampleContext($example, $split, $holdoutCodes, $writer);

                    if ($context === null) {
                        continue;
                    }

                    [$rowSplit, $repKind, $text] = $context;
                    $chosen = json_encode($example->gold?->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $rejected = json_encode($parent->result->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                    $userPart = $this->inputBuilder->userTurn($text, null, null);

                    $line = $this->dpoRow($dpoSchema, $composed->systemPart, $userPart, $chosen, $rejected);
                    $line['meta'] = $this->rowMeta($example, $rowSplit, $repKind);

                    $writer->append($line, $example->source, $rowSplit, $repKind);
                }
            },
        );
    }

    /**
     * @param  array<string, int>  $initialSkipped
     * @param  list<string>  $warnings
     * @param  callable(ExportWriter, ComposedPrompt): void  $populate
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function runExport(array $initialSkipped, array $warnings, callable $populate): array
    {
        $composed = $this->inputBuilder->composePrompt();
        $writer = new ExportWriter($initialSkipped);

        try {
            $populate($writer, $composed);

            return $writer->finish($composed->promptVersionSet, $composed->rulesHash, $warnings);
        } catch (\Throwable $e) {
            $writer->close();

            throw $e;
        }
    }

    /**
     * @param  list<string>|null  $holdoutCodes
     * @return array{0: string, 1: string, 2: string}|null
     */
    private function resolveExampleContext(DatasetExample $example, string $split, ?array $holdoutCodes, ExportWriter $writer): ?array
    {
        $rowSplit = $this->splitOf($example, $holdoutCodes);

        if ($split !== 'all' && $rowSplit !== $split) {
            return null;
        }

        $resolved = $this->inputBuilder->resolveInput($example->documentVersion);

        if (isset($resolved['skip'])) {
            $writer->skip($resolved['skip']);

            return null;
        }

        return [$rowSplit, $resolved['representation_kind']->value, $resolved['text']];
    }

    /**
     * @return array<string, mixed>
     */
    private function dpoRow(string $schema, string $systemPart, string $userPart, string $chosen, string $rejected): array
    {
        if ($schema === 'standard') {
            return [
                'prompt' => $systemPart."\n\n---\n\n".$userPart,
                'chosen' => $chosen,
                'rejected' => $rejected,
            ];
        }

        return [
            'prompt' => [
                ['role' => 'system', 'content' => $systemPart],
                ['role' => 'user', 'content' => $userPart],
            ],
            'chosen' => [['role' => 'assistant', 'content' => $chosen]],
            'rejected' => [['role' => 'assistant', 'content' => $rejected]],
        ];
    }

    /**
     * @return array{fixture_code: string|null, document_type: string, split: string, source: string, trust: string, representation_kind: string, document_version_id: int, example_version: int}
     */
    private function rowMeta(DatasetExample $example, string $split, string $representationKind): array
    {
        return [
            'fixture_code' => $example->fixture?->code,
            'document_type' => $example->document_type,
            'split' => $split,
            'source' => $example->source,
            'trust' => $example->source === 'deterministic' ? 'deterministic' : 'human',
            'representation_kind' => $representationKind,
            'document_version_id' => $example->document_version_id,
            'example_version' => $example->version,
        ];
    }

    /**
     * @param  list<string>|null  $holdoutCodes
     */
    private function splitOf(DatasetExample $example, ?array $holdoutCodes): string
    {
        $code = $example->fixture?->code;

        if ($holdoutCodes === null || $code === null) {
            return 'none';
        }

        return in_array($code, $holdoutCodes, true) ? 'holdout' : 'tune';
    }

    /**
     * @return array{0: list<string>|null, 1: list<string>}
     */
    private function loadHoldoutCodes(string $split): array
    {
        $path = config('ai.fixtures.splits_path');

        if (is_string($path) && $path !== '' && ! is_file($path)) {
            if ($split !== 'all') {
                throw new RuntimeException("Файл разбиения не найден: {$path}. Для --split={$split} прогони `estimate:eval:run --write-splits`.");
            }

            return [null, ["Файл разбиения не найден: {$path}. Все строки помечены split=none."]];
        }

        return [$this->splitPlanner->loadHoldoutCodes(), []];
    }

    public static function exporterVersion(): int
    {
        return self::EXPORTER_VERSION;
    }

    /** @return list<array<string, mixed>> */
    private function goldEstimates(DatasetExample $example): array
    {
        $estimates = $example->gold?->get('estimates') ?? [];

        if (! is_array($estimates)) {
            throw new RuntimeException('Gold-пример содержит невалидный список смет.');
        }

        /** @var list<array<string, mixed>> $normalized */
        $normalized = [];

        foreach ($estimates as $estimate) {
            if (! is_array($estimate)) {
                throw new RuntimeException('Gold-пример содержит невалидную смету.');
            }

            foreach (array_keys($estimate) as $key) {
                if (! is_string($key)) {
                    throw new RuntimeException('Gold-смета содержит невалидный ключ.');
                }
            }

            /** @var array<string, mixed> $estimate */
            $normalized[] = $estimate;
        }

        return $normalized;
    }

    private function nullableOrdinal(mixed $ordinal): ?int
    {
        if ($ordinal === null) {
            return null;
        }

        if (is_int($ordinal)) {
            return $ordinal;
        }

        if (is_string($ordinal) && ctype_digit($ordinal)) {
            return (int) $ordinal;
        }

        throw new RuntimeException('Gold-смета содержит невалидный ordinal.');
    }

    private function nullableEstimateNumber(mixed $estimateNumber): ?string
    {
        if ($estimateNumber === null || is_string($estimateNumber)) {
            return $estimateNumber;
        }

        throw new RuntimeException('Gold-смета содержит невалидный estimate_number.');
    }
}
