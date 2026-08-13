<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\DatasetExample;
use App\Repositories\Procurement\Ai\AnalysisReadRepository;
use App\Repositories\Procurement\Ai\DatasetExampleReadRepository;
use RuntimeException;

/**
 * Экспорт `prc_ai_dataset_examples` (gold) в обучающий датасет (`estimate:dataset:export`, P7.1,
 * D56):
 * - `sft` — JSONL chat-format. `system`/`user` строятся {@see DatasetInputBuilder} (ЕДИНЫЙ
 *   строитель входа для обоих форматов) — `user` несёт ФАКТИЧЕСКИЙ текст представления документа
 *   (`prc_document_representations`), а не ссылку. `assistant` — `{rows, incomplete_items}`
 *   контракта шага строк (§3.2 спеки).
 * - `dpo` — preference-пары `chosen`(gold-снапшот)/`rejected`(`Analysis.result` предшественника):
 *   предшественник резолвится через СОХРАНЁННЫЙ `analysis_id` записи (D56) — резолв через активный
 *   анализ версии документа резолвил бы ОДИН И ТОТ ЖЕ предшественник для каждой gold-версии,
 *   чинимый этим item'ом дефект.
 *
 * Основание доверия (запись экспортируема ⟺ `source=deterministic` ИЛИ непустой `verified_at`) и
 * счётчики пропусков — {@see DatasetExampleReadRepository}. Пропуск ВСЕГДА считается и печатается
 * с причиной (`skipped`, манифест) — молчаливого исключения нет.
 */
final readonly class DatasetExporter
{
    private const EXPORTER_VERSION = 2;

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
        return $format === 'sft'
            ? $this->writeSft($task, $split, $sources)
            : $this->writeDpo($task, $split, $sources, $dpoSchema);
    }

    /**
     * @param  list<string>  $sources
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function writeSft(string $task, string $split, array $sources): array
    {
        $composed = $this->inputBuilder->composePrompt();
        [$holdoutCodes, $warnings] = $this->loadHoldoutCodes();

        $stream = $this->temporaryStream();
        $count = 0;
        $skipped = ['not_trusted' => $this->examples->countNotTrusted($task, $sources)];
        $bySource = [];
        $bySplit = [];
        $byRepresentationKind = [];

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
                    'meta' => $this->rowMeta($example, $rowSplit, $resolved['representation_kind']->value),
                ];

                fwrite($stream, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
                $count++;
                $bySource[$example->source] = ($bySource[$example->source] ?? 0) + 1;
                $bySplit[$rowSplit] = ($bySplit[$rowSplit] ?? 0) + 1;
                $byRepresentationKind[$resolved['representation_kind']->value] = ($byRepresentationKind[$resolved['representation_kind']->value] ?? 0) + 1;
            }
        }

        rewind($stream);

        /** @var array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>} $summary */
        $summary = [
            'examples' => $count,
            'skipped' => array_filter($skipped),
            'by_source' => $bySource,
            'by_split' => $bySplit,
            'by_representation_kind' => $byRepresentationKind,
            'prompt_version_set' => $composed->promptVersionSet,
            'rules_hash' => $composed->rulesHash,
            'warnings' => $warnings,
        ];

        return [$summary, $stream];
    }

    /**
     * @param  list<string>  $sources
     * @return array{0: array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>}, 1: resource}
     */
    private function writeDpo(string $task, string $split, array $sources, string $dpoSchema): array
    {
        $composed = $this->inputBuilder->composePrompt();
        [$holdoutCodes, $warnings] = $this->loadHoldoutCodes();

        $stream = $this->temporaryStream();
        $count = 0;
        $skipped = [
            'not_trusted' => $this->examples->countUntrustedCorrected($task, $sources),
            'no_analysis_id' => $this->examples->countMissingAnalysisId($task, $sources),
        ];
        $bySource = [];
        $bySplit = [];
        $byRepresentationKind = [];

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

            $chosen = json_encode($example->gold?->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $rejected = json_encode($parent->result->get('estimates') ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $userPart = $this->inputBuilder->userTurn($resolved['text'], null, null);

            $line = $this->dpoRow($dpoSchema, $composed->systemPart, $userPart, $chosen, $rejected);
            $line['meta'] = $this->rowMeta($example, $rowSplit, $resolved['representation_kind']->value);

            fwrite($stream, json_encode($line, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n");
            $count++;
            $bySource[$example->source] = ($bySource[$example->source] ?? 0) + 1;
            $bySplit[$rowSplit] = ($bySplit[$rowSplit] ?? 0) + 1;
            $byRepresentationKind[$resolved['representation_kind']->value] = ($byRepresentationKind[$resolved['representation_kind']->value] ?? 0) + 1;
        }

        rewind($stream);

        /** @var array{examples: int, skipped: array<string, int>, by_source: array<string, int>, by_split: array<string, int>, by_representation_kind: array<string, int>, prompt_version_set: array<string, int>, rules_hash: string, warnings: list<string>} $summary */
        $summary = [
            'examples' => $count,
            'skipped' => array_filter($skipped),
            'by_source' => $bySource,
            'by_split' => $bySplit,
            'by_representation_kind' => $byRepresentationKind,
            'prompt_version_set' => $composed->promptVersionSet,
            'rules_hash' => $composed->rulesHash,
            'warnings' => $warnings,
        ];

        return [$summary, $stream];
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
     * @param  list<string>  $holdoutCodes
     */
    private function splitOf(DatasetExample $example, array $holdoutCodes): string
    {
        $code = $example->fixture?->code;

        if ($code === null) {
            return 'none';
        }

        return in_array($code, $holdoutCodes, true) ? 'holdout' : 'tune';
    }

    /**
     * `estimates/eval-splits.json` отсутствует → `split=none` у ВСЕХ строк + предупреждение в
     * сводке (Implementation Rules P7.1) — не СТОП: экспорт легален и без файла разбиения.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function loadHoldoutCodes(): array
    {
        try {
            return [$this->splitPlanner->loadHoldoutCodes(), []];
        } catch (RuntimeException) {
            return [[], ['estimates/eval-splits.json не найден — все строки помечены split=none.']];
        }
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
