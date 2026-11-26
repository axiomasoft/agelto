<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

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
        $composed = $this->inputBuilder->composePrompt();

        $stream = $this->temporaryStream();
        $count = 0;
        $skipped = ['not_trusted' => $this->examples->countNotTrusted($task, $sources)];
        $bySource = [];
        $bySplit = [];
        $byRepresentationKind = [];

        try {
            foreach ($this->examples->exportableSft($task, $sources) as $example) {
                /** @var DatasetExample $example */
                $rowSplit = $this->splitOf($example, $holdoutCodes);

                if ($split !== 'all' && $rowSplit !== $split) {
                    continue;
                }

                $resolved = $this->inputBuilder->resolveInput($example->documentVersion);

                if (isset($resolved['skip'])) {
                    $skipped[$resolved['skip']] = ($skipped[$resolved['skip']] ?? 0) + 1;

                    continue;
                }

                $estimates = $this->goldEstimates($example);
                $repKind = $resolved['representation_kind']->value;

                foreach ($estimates as $estimate) {
                    $ordinal = $this->nullableOrdinal($estimate['ordinal'] ?? null);
                    $estimateNumber = $this->nullableEstimateNumber($estimate['estimate_number'] ?? null);
                    $rows = $estimate['rows'] ?? [];

                    if (! is_array($rows)) {
                        throw new RuntimeException('Gold-смета содержит невалидный список строк.');
                    }

                    $line = [
                        'messages' => [
                            ['role' => 'system', 'content' => $composed->systemPart],
                            ['role' => 'user', 'content' => $this->inputBuilder->userTurn(
                                $resolved['text'],
                                $ordinal,
                                $estimateNumber,
                            )],
                            ['role' => 'assistant', 'content' => json_encode([
                                'rows' => $rows,
                                'incomplete_items' => [],
                            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                        ],
                        'meta' => $this->rowMeta($example, $rowSplit, $repKind),
                    ];

                    $this->appendExportLine($stream, $line, $example->source, $rowSplit, $repKind, $count, $bySource, $bySplit, $byRepresentationKind);
                }
            }

            rewind($stream);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $e;
        }

        return [
            $this->buildSummary($count, $skipped, $bySource, $bySplit, $byRepresentationKind, $composed->promptVersionSet, $composed->rulesHash, $warnings),
            $stream,
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  list<string>|null  $holdoutCodes
     * @param  list<string>  $warnings
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function writeDpo(string $task, string $split, array $sources, string $dpoSchema, ?array $holdoutCodes, array $warnings): array
    {
        $composed = $this->inputBuilder->composePrompt();

        $stream = $this->temporaryStream();
        $count = 0;
        $skipped = [
            'not_trusted' => $this->examples->countUntrustedCorrected($task, $sources),
            'no_analysis_id' => $this->examples->countMissingAnalysisId($task, $sources),
        ];
        $bySource = [];
        $bySplit = [];
        $byRepresentationKind = [];

        try {
            foreach ($this->examples->exportableDpo($task, $sources) as $example) {
                /** @var DatasetExample $example */
                /** @var Analysis|null $analysis */
                $analysis = $example->analysis;

                if ($analysis === null || $analysis->parent_analysis_id === null) {
                    $skipped['no_parent_analysis'] = ($skipped['no_parent_analysis'] ?? 0) + 1;

                    continue;
                }

                $parent = $this->analyses->parentOf($analysis);

                if ($parent === null || $parent->result === null) {
                    $skipped['parent_result_empty'] = ($skipped['parent_result_empty'] ?? 0) + 1;

                    continue;
                }

                $rowSplit = $this->splitOf($example, $holdoutCodes);

                if ($split !== 'all' && $rowSplit !== $split) {
                    continue;
                }

                $resolved = $this->inputBuilder->resolveInput($example->documentVersion);

                if (isset($resolved['skip'])) {
                    $skipped[$resolved['skip']] = ($skipped[$resolved['skip']] ?? 0) + 1;

                    continue;
                }

                $repKind = $resolved['representation_kind']->value;
                $chosen = json_encode($example->gold?->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $rejected = json_encode($parent->result->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $userPart = $this->inputBuilder->userTurn($resolved['text'], null, null);

                $line = $this->dpoRow($dpoSchema, $composed->systemPart, $userPart, $chosen, $rejected);
                $line['meta'] = $this->rowMeta($example, $rowSplit, $repKind);

                $this->appendExportLine($stream, $line, $example->source, $rowSplit, $repKind, $count, $bySource, $bySplit, $byRepresentationKind);
            }

            rewind($stream);
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw $e;
        }

        return [
            $this->buildSummary($count, $skipped, $bySource, $bySplit, $byRepresentationKind, $composed->promptVersionSet, $composed->rulesHash, $warnings),
            $stream,
        ];
    }

    /**
     * @param  resource  $stream
     * @param  array<string, mixed>  $line
     * @param  array<string, int>  $bySource
     * @param  array<string, int>  $bySplit
     * @param  array<string, int>  $byRepresentationKind
     */
    private function appendExportLine(
        $stream,
        array $line,
        string $source,
        string $rowSplit,
        string $repKind,
        int &$count,
        array &$bySource,
        array &$bySplit,
        array &$byRepresentationKind,
    ): void {
        fwrite($stream, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
        $count++;
        $bySource[$source] = ($bySource[$source] ?? 0) + 1;
        $bySplit[$rowSplit] = ($bySplit[$rowSplit] ?? 0) + 1;
        $byRepresentationKind[$repKind] = ($byRepresentationKind[$repKind] ?? 0) + 1;
    }

    /**
     * @param  array<string, int>  $skipped
     * @param  array<string, int>  $bySource
     * @param  array<string, int>  $bySplit
     * @param  array<string, int>  $byRepresentationKind
     * @param  array<string, int>  $promptVersionSet
     * @param  list<string>  $warnings
     * @return array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}
     */
    private function buildSummary(
        int $count,
        array $skipped,
        array $bySource,
        array $bySplit,
        array $byRepresentationKind,
        array $promptVersionSet,
        string $rulesHash,
        array $warnings,
    ): array {
        return [
            'examples' => $count,
            'skipped' => array_filter($skipped),
            'by_source' => $bySource,
            'by_split' => $bySplit,
            'by_representation_kind' => $byRepresentationKind,
            'prompt_version_set' => $promptVersionSet,
            'rules_hash' => $rulesHash,
            'warnings' => $warnings,
        ];
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

    /** @return resource */
    private function temporaryStream()
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('Не удалось открыть временный поток для экспорта датасета.');
        }

        return $stream;
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
