<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Concerns\RendersReport;
use App\Exceptions\Procurement\OperationFailed;
use App\Repositories\Procurement\Ai\EvalRunReadRepository;
use App\Services\Procurement\Tuning\EvalConsolePresenter;
use App\Services\Procurement\Tuning\EvalRunComparator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Сравнение двух `EvalRun` по критерию приёмки патча промта: дельты `overall.f1`/`row_coverage`/`per_field` на подвыборках `tune`/`holdout` и
 * дельта `f1` по каждому эталону, вердикт `accept`/`reject` — {@see EvalRunComparator}. НИ ОДНОГО
 * LLM-вызова не делает — читает уже посчитанные `prc_ai_eval_results` двух прогонов.
 */
#[Description('Сравнение двух EvalRun по критерию приёмки патча промта (tune/holdout)')]
#[Signature('estimate:eval:compare
        {--base= : id базового прогона (EvalRun)}
        {--candidate= : id прогона-кандидата (EvalRun)}
        {--criterion=patch : режим сравнения (patch|no-regression|noise-aware, дефолт — сегодняшнее поведение)}
        {--noise-pair= : id,id нулевой пары для --criterion=noise-aware (обязателен для него, дефолта нет)}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}')]
final class EvalCompareCommand extends Command
{
    use RendersReport;

    public function __construct(
        private readonly EvalRunComparator $comparator,
        private readonly EvalRunReadRepository $evalRuns,
    ) {
        parent::__construct();
    }

    public function handle(EvalConsolePresenter $presenter = new EvalConsolePresenter): int
    {
        $format = (string) $this->option('format');
        $criterion = (string) $this->option('criterion');

        try {
            $this->guardFormat($format);

            $inputs = $presenter->resolveCompareInputs(
                evalRuns: $this->evalRuns,
                baseOption: $this->option('base'),
                candidateOption: $this->option('candidate'),
                criterion: $criterion,
                noisePairOption: $this->option('noise-pair'),
            );

            $result = $this->comparator->compare(
                base: $inputs['base'],
                candidate: $inputs['candidate'],
                criterion: $criterion,
                noisePairBase: $inputs['noisePairBase'],
                noisePairCandidate: $inputs['noisePairCandidate'],
            );
        } catch (OperationFailed|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rendered = $format === 'json' ? $this->renderJson($result) : $presenter->renderComparisonMarkdown($result);

        $this->writeArtifact($this->option('out'), $rendered);

        $this->line($rendered);
        $this->info("Вердикт: {$result['verdict']}");

        return self::SUCCESS;
    }
}
