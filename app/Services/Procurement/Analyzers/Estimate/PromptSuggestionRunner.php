<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Fixture\ItemOutcome;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Exceptions\Procurement\OperationFailed;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Contracts\Dto\ProgressTick;
use App\Services\Procurement\Contracts\ProgressReporter;
use App\Services\Procurement\Fixture\FixtureCaseResolver;
use App\Services\Procurement\Tuning\PromptCritic;
use InvalidArgumentException;

/**
 * Цикл «кейсы × шаги» с `--limit` (`estimate:prompts:suggest`, вынесено из
 * `PromptsSuggestCommand`): резолв кейса и gold — {@see FixtureCaseResolver}, ОБЩИЙ с
 * `estimate:eval:diff` — резолвер БРОСАЕТ всегда, здесь ловится
 * ПОКЕЙСНО и записывается `ItemOutcome(rejected)` (цикл продолжается, как раньше).
 */
final readonly class PromptSuggestionRunner
{
    public function __construct(
        private EstimateDiffReport $diffReport,
        private FixtureCaseResolver $resolver,
        private PromptCritic $critic,
        private DocumentAnalyzerRegistry $registry,
        private FixtureStoreRepository $fixtures,
    ) {}

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $stepOptions
     * @return array{created: int, outcomes: list<ItemOutcome>}
     */
    public function run(array $codes, ?int $analysisId, array $stepOptions, bool $dryRun, ?int $limit, ?ProgressReporter $progress = null): array
    {
        $cases = [];
        $outcomes = [];

        foreach ($codes as $code) {
            try {
                $cases[] = $this->resolver->resolveByCode($code);
            } catch (OperationFailed $e) {
                $outcomes[] = new ItemOutcome($code, 'rejected', $e->getMessage());
            }
        }

        if ($analysisId !== null) {
            try {
                $cases[] = $this->resolver->resolveByAnalysisId($analysisId);
            } catch (OperationFailed $e) {
                $outcomes[] = new ItemOutcome("analysis:{$analysisId}", 'rejected', $e->getMessage());
            }
        }

        $created = 0;

        foreach ($cases as [$code, $analysis, $gold]) {
            $goldEstimates = $this->estimateList($gold['estimates'] ?? []);
            $predictedEstimates = $this->estimateList($analysis->result['estimates'] ?? []);
            $diff = $this->diffReport->build($goldEstimates, $predictedEstimates)->toArray();
            $fixtureId = $this->fixtures->findByCode($code)?->id;

            // Порядок инвертирован: предмет (Analysis несёт document_type) резолвится
            // раньше шагов — значение `step` без типа документа неоднозначно по контракту (§5.4 п.3).
            $documentType = $analysis->document_type !== null
                ? (AiDocumentType::tryFrom($analysis->document_type) ?? DocumentTypeScope::Any)
                : DocumentTypeScope::Any;

            try {
                $steps = array_map(
                    fn (string $value) => $this->registry->promptStepFor($documentType, $value),
                    $stepOptions,
                );
            } catch (InvalidArgumentException $e) {
                $outcomes[] = new ItemOutcome($code, 'rejected', "неизвестный --step для document_type={$documentType->value}: {$e->getMessage()}");

                continue;
            }

            foreach ($steps as $step) {
                if ($limit !== null && $created >= $limit) {
                    $outcomes[] = new ItemOutcome($code, 'skipped', "достигнут --limit={$limit}, остановка");

                    return ['created' => $created, 'outcomes' => $outcomes];
                }

                if ($dryRun) {
                    $progress?->report(new ProgressTick(
                        count($outcomes) + 1,
                        count($cases),
                        "dry-run: код={$code} analysis=#{$analysis->id} step={$step->value}",
                        json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ));
                    $outcomes[] = new ItemOutcome($code, 'dry-run', "step={$step->value}");

                    continue;
                }

                $suggestion = $this->critic->critique($step, $diff, $analysis->id, $fixtureId);

                if ($suggestion === null) {
                    $outcomes[] = new ItemOutcome($code, 'skipped', "step={$step->value}: предложение — дубль, пропущено");

                    continue;
                }

                $created++;
                $outcomes[] = new ItemOutcome($code, 'created', "step={$step->value}: создано предложение #{$suggestion->id} ({$suggestion->kind->value})");
            }
        }

        return ['created' => $created, 'outcomes' => $outcomes];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function estimateList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $estimates = [];

        foreach ($value as $estimate) {
            if (! is_array($estimate)) {
                continue;
            }

            $payload = [];

            foreach ($estimate as $key => $field) {
                if (is_string($key)) {
                    $payload[$key] = $field;
                }
            }

            $estimates[] = $payload;
        }

        return $estimates;
    }
}
