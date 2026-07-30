<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStep;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Fixture;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\FixtureStoreRepository;
use App\Services\Procurement\Analyzers\Estimate\EstimateDiffReport;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;
use App\Services\Procurement\Tuning\PromptCritic;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;

/**
 * Дешёвый LLM-критик промтов по эталону (P7.4, D8/D59/D61): по каждому `--code`/`--analysis`
 * строит diff-отчёт (`EstimateDiffReport`, тот же резолв, что `ai:eval:diff`) и для каждого
 * `--step` предлагает патч/hint через {@see PromptCritic}. Создаёт ТОЛЬКО записи `pending` —
 * ни одного изменения в `resources/ai/prompts/**` (это делает `ai:prompts:apply` после апрува,
 * D8).
 */
final class PromptsSuggestCommand extends Command
{
    protected $signature = 'ai:prompts:suggest
        {--code=* : коды эталонов}
        {--analysis= : id анализа вместо кода}
        {--step=* : шаги (PromptStep), по которым предлагать}
        {--dry-run : не вызывать LLM, показать вход критика}
        {--limit= : максимум предложений за прогон}';

    protected $description = 'LLM-критик промтов по diff-отчёту эталона — кладёт предложения pending в очередь';

    public function __construct(
        private readonly EstimateDiffReport $diffReport,
        private readonly FixtureStoreRepository $fixtures,
        private readonly DocumentStoreRepository $documents,
        private readonly PromptCritic $critic,
        private readonly DocumentAnalyzerRegistry $registry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $codes = array_map('strval', (array) $this->option('code'));
        $analysisOption = $this->option('analysis');
        $stepOptions = array_map('strval', (array) $this->option('step'));
        $dryRun = (bool) $this->option('dry-run');
        $limitOption = $this->option('limit');
        $limit = $limitOption !== null ? (int) $limitOption : null;

        if ($codes === [] && $analysisOption === null) {
            $this->error('Нужен хотя бы один --code=<эталон> или --analysis=<id>.');

            return self::FAILURE;
        }

        if ($stepOptions === []) {
            $this->error('Нужен хотя бы один --step=<PromptStep>.');

            return self::FAILURE;
        }

        $cases = [];

        foreach ($codes as $code) {
            $cases[] = $this->resolveByCode($code);
        }

        if ($analysisOption !== null) {
            $cases[] = $this->resolveByAnalysis((int) $analysisOption);
        }

        $created = 0;

        foreach ($cases as $case) {
            if ($case === null) {
                continue;
            }

            [$code, $analysis, $gold] = $case;
            $goldEstimates = (array) ($gold['estimates'] ?? []);
            $predictedEstimates = (array) ($analysis->result['estimates'] ?? []);
            $diff = $this->diffReport->build($goldEstimates, $predictedEstimates)->toArray();
            $fixtureId = $this->fixtures->findByCode($code)?->id;

            // Порядок инвертирован (D101 п. г): предмет (Analysis несёт document_type) резолвится
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
                $this->error("Неизвестный --step для document_type={$documentType->value}: {$e->getMessage()}");

                continue;
            }

            foreach ($steps as $step) {
                if ($limit !== null && $created >= $limit) {
                    $this->info("Достигнут --limit={$limit}, остановка.");

                    return self::SUCCESS;
                }

                if ($dryRun) {
                    $this->line("dry-run: код={$code} analysis=#{$analysis->id} step={$step->value}");
                    $this->line(json_encode($diff, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

                    continue;
                }

                $suggestion = $this->critic->critique($step, $diff, $analysis->id, $fixtureId);

                if ($suggestion === null) {
                    $this->line("код={$code} step={$step->value}: предложение — дубль, пропущено.");

                    continue;
                }

                $created++;
                $this->info("код={$code} step={$step->value}: создано предложение #{$suggestion->id} ({$suggestion->kind->value}).");
            }
        }

        $this->info("Создано предложений: {$created}.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: Analysis, 2: array<string, mixed>}|null
     */
    private function resolveByCode(string $code): ?array
    {
        $analysis = $this->latestExtractionAnalysis($code);

        if ($analysis === null) {
            $this->error("Активный Analysis(step=extraction) для эталона {$code} не найден — прогони ai:eval:run.");

            return null;
        }

        return $this->withGold($code, $analysis);
    }

    /**
     * @return array{0: string, 1: Analysis, 2: array<string, mixed>}|null
     */
    private function resolveByAnalysis(int $analysisId): ?array
    {
        $analysis = Analysis::query()->where('step', AnalysisStep::Extraction)->find($analysisId);

        if ($analysis === null) {
            $this->error("Analysis #{$analysisId} (step=extraction) не найден.");

            return null;
        }

        $code = $this->fixtureCodeFor($analysis);

        if ($code === null) {
            $this->error("Analysis #{$analysisId} не связан с эталоном (external_id документа не по конвенции fixture:<CODE>).");

            return null;
        }

        return $this->withGold($code, $analysis);
    }

    /**
     * @return array{0: string, 1: Analysis, 2: array<string, mixed>}|null
     */
    private function withGold(string $code, Analysis $analysis): ?array
    {
        $goldPath = ((string) config('ai.fixtures.gold_dir'))."/{$code}.gold.json";

        if (! is_file($goldPath)) {
            $this->error("Gold-файл не найден: {$goldPath}");

            return null;
        }

        try {
            $gold = json_decode((string) file_get_contents($goldPath), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Gold-файл не распарсен: {$e->getMessage()}");

            return null;
        }

        return [$code, $analysis, (array) $gold];
    }

    private function latestExtractionAnalysis(string $code): ?Analysis
    {
        $fixture = $this->fixtures->findByCode($code);

        if ($fixture === null) {
            return null;
        }

        $document = $fixture->document;

        if ($document === null) {
            return null;
        }

        $version = $this->documents->findVersionByHash($document->id, $fixture->sha256);

        if ($version === null) {
            return null;
        }

        return Analysis::query()
            ->active()
            ->where('document_version_id', $version->id)
            ->where('step', AnalysisStep::Extraction)
            ->first();
    }

    private function fixtureCodeFor(Analysis $analysis): ?string
    {
        $externalId = $analysis->documentVersion?->document?->external_id;

        if ($externalId === null || ! str_starts_with($externalId, 'fixture:')) {
            return null;
        }

        $code = substr($externalId, strlen('fixture:'));

        return Fixture::query()->where('code', $code)->exists() ? $code : null;
    }
}
