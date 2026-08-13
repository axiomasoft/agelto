<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Console\Concerns\RendersReport;
use App\Exceptions\Procurement\OperationFailed;
use App\Repositories\Procurement\Ai\EvalRunReadRepository;
use App\Services\Procurement\Tuning\EvalRunComparator;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * @phpstan-type EvalAggregate array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int}
 * @phpstan-type EvalSubsample array{base: EvalAggregate, candidate: EvalAggregate, delta: array{f1: float, row_coverage: float, per_field: array<string, float>}}
 * @phpstan-type EvalProfile array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int}
 * @phpstan-type EvalComparison array{base_run_id: int, candidate_run_id: int, subsamples: array<string, EvalSubsample>, per_fixture: array<string, float>, verdict: 'accept'|'reject', reasons: list<string>, noise_basis?: array{pair_base_id: int, pair_candidate_id: int, subsample_thresholds: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}, profile?: array{base: EvalProfile, candidate: EvalProfile, output_drop: int, output_drop_noise_threshold: int}}
 *
 * Сравнение двух `EvalRun` по критерию приёмки патча промта (P7.5, D63 + правило отбора
 * D119/D120): дельты `overall.f1`/`row_coverage`/`per_field` на подвыборках `tune`/`holdout` и
 * дельта `f1` по каждому эталону, вердикт `accept`/`reject` — {@see EvalRunComparator}. НИ ОДНОГО
 * LLM-вызова не делает — читает уже посчитанные `prc_ai_eval_results` двух прогонов.
 * `--criterion` (P2.2, D24/D91; `noise-aware` — P2.13, D108/D109) переключает режим сравнения
 * (`patch`|`no-regression`|`noise-aware`), дефолт сохраняет сегодняшнее поведение — тюнинг-контур
 * `2026.07.26-EST-TRAIN` его не замечает.
 */
final class EvalCompareCommand extends Command
{
    use RendersReport;

    protected $signature = 'estimate:eval:compare
        {--base= : id базового прогона (EvalRun)}
        {--candidate= : id прогона-кандидата (EvalRun)}
        {--criterion=patch : режим сравнения (patch|no-regression|noise-aware, дефолт — сегодняшнее поведение)}
        {--noise-pair= : id,id нулевой пары для --criterion=noise-aware (обязателен для него, дефолта нет)}
        {--format=json : формат вывода (json|md)}
        {--out= : путь файла (репо-относительный)}';

    protected $description = 'Сравнение двух EvalRun по критерию приёмки патча промта (tune/holdout, D63)';

    public function __construct(
        private readonly EvalRunComparator $comparator,
        private readonly EvalRunReadRepository $evalRuns,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $format = (string) $this->option('format');

        try {
            $this->guardFormat($format);
        } catch (OperationFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $baseOption = $this->option('base');
        $candidateOption = $this->option('candidate');

        if ($baseOption === null || $candidateOption === null) {
            $this->error('Нужны --base=<id прогона> и --candidate=<id прогона>.');

            return self::FAILURE;
        }

        $criterion = (string) $this->option('criterion');

        if (! in_array($criterion, [EvalRunComparator::CRITERION_PATCH, EvalRunComparator::CRITERION_NO_REGRESSION, EvalRunComparator::CRITERION_NOISE_AWARE], true)) {
            $this->error('Неизвестный --criterion — ожидается patch|no-regression|noise-aware.');

            return self::FAILURE;
        }

        $noisePairOption = $this->option('noise-pair');

        if ($criterion === EvalRunComparator::CRITERION_NOISE_AWARE && $noisePairOption === null) {
            $this->error('Режим noise-aware требует --noise-pair=<id>,<id> — дефолтной нулевой пары нет.');

            return self::FAILURE;
        }

        $noisePairBase = null;
        $noisePairCandidate = null;

        if ($noisePairOption !== null) {
            $noisePairIds = $this->parseNoisePairOption((string) $noisePairOption);

            if ($noisePairIds === null) {
                $this->error('--noise-pair ожидает `<id>,<id>` (два целых через запятую).');

                return self::FAILURE;
            }

            $noisePairBase = $this->evalRuns->find($noisePairIds[0]);
            $noisePairCandidate = $this->evalRuns->find($noisePairIds[1]);

            if ($noisePairBase === null || $noisePairCandidate === null) {
                $this->error('EvalRun нулевой пары не найден — проверь --noise-pair.');

                return self::FAILURE;
            }
        }

        $base = $this->evalRuns->find((int) $baseOption);
        $candidate = $this->evalRuns->find((int) $candidateOption);

        if ($base === null || $candidate === null) {
            $this->error('EvalRun не найден — проверь --base/--candidate.');

            return self::FAILURE;
        }

        try {
            $result = $this->comparator->compare(
                base: $base,
                candidate: $candidate,
                criterion: $criterion,
                noisePairBase: $noisePairBase,
                noisePairCandidate: $noisePairCandidate,
            );
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rendered = $format === 'json' ? $this->renderJson($result) : $this->renderMarkdown($result);

        $this->writeArtifact($this->option('out'), $rendered);

        $this->line($rendered);
        $this->info("Вердикт: {$result['verdict']}");

        return self::SUCCESS;
    }

    /**
     * @param  EvalComparison  $result
     */
    private function renderMarkdown(array $result): string
    {
        $lines = [];
        $lines[] = "# Сравнение EvalRun #{$result['base_run_id']} → #{$result['candidate_run_id']}";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$result['verdict']}";
        $lines[] = '';

        if ($result['reasons'] !== []) {
            $lines[] = '## Причины отказа';
            $lines[] = '';

            foreach ($result['reasons'] as $reason) {
                $lines[] = "- {$reason}";
            }

            $lines[] = '';
        }

        $lines[] = '## Подвыборки';
        $lines[] = '';
        $lines[] = '| подвыборка | base.f1 | candidate.f1 | Δf1 | base.row_coverage | candidate.row_coverage | Δrow_coverage |';
        $lines[] = '|:--|--:|--:|--:|--:|--:|--:|';

        foreach ($result['subsamples'] as $name => $subsample) {
            $lines[] = sprintf(
                '| %s | %.4f | %.4f | %.4f | %.4f | %.4f | %.4f |',
                $name,
                $subsample['base']['f1'],
                $subsample['candidate']['f1'],
                $subsample['delta']['f1'],
                $subsample['base']['row_coverage'],
                $subsample['candidate']['row_coverage'],
                $subsample['delta']['row_coverage'],
            );
        }

        $lines[] = '';
        $lines[] = '## Дельта f1 по эталону';
        $lines[] = '';
        $lines[] = '| код эталона | Δf1 |';
        $lines[] = '|:--|--:|';

        foreach ($result['per_fixture'] as $code => $delta) {
            $lines[] = sprintf('| %s | %.4f |', $code, $delta);
        }

        if (array_key_exists('noise_basis', $result)) {
            $basis = $result['noise_basis'];
            $lines[] = '';
            $lines[] = "## Основание порогов (шум пары #{$basis['pair_base_id']}/#{$basis['pair_candidate_id']})";
            $lines[] = '';
            $lines[] = '| подвыборка | порог Δf1 | порог Δrow_coverage |';
            $lines[] = '|:--|--:|--:|';

            foreach ($basis['subsample_thresholds'] as $name => $thresholds) {
                $lines[] = sprintf('| %s | %.4f | %.4f |', $name, $thresholds['f1'], $thresholds['row_coverage']);
            }

            $lines[] = '';
            $lines[] = sprintf(
                'Порог per-fixture Δf1 (единый для всех эталонов, как и старый `PER_FIXTURE_F1_MAX_DROP`): %.4f — худший эталон нулевой пары: %s.',
                $basis['per_fixture_threshold'],
                $basis['per_fixture_worst_code'],
            );
        }

        if (array_key_exists('profile', $result)) {
            $profile = $result['profile'];
            $lines[] = '';
            $lines[] = '## Профиль вызовов';
            $lines[] = '';
            $lines[] = '| прогон | calls | prompt_tokens | completion_tokens | средний prompt/вызов | cost_micros |';
            $lines[] = '|:--|--:|--:|--:|--:|--:|';
            $lines[] = sprintf(
                '| base #%d | %d | %d | %d | %.1f | %d |',
                $result['base_run_id'],
                $profile['base']['calls'],
                $profile['base']['prompt_tokens'],
                $profile['base']['completion_tokens'],
                $profile['base']['avg_prompt_tokens'],
                $profile['base']['cost_micros'],
            );
            $lines[] = sprintf(
                '| candidate #%d | %d | %d | %d | %.1f | %d |',
                $result['candidate_run_id'],
                $profile['candidate']['calls'],
                $profile['candidate']['prompt_tokens'],
                $profile['candidate']['completion_tokens'],
                $profile['candidate']['avg_prompt_tokens'],
                $profile['candidate']['cost_micros'],
            );
            $lines[] = '';
            $lines[] = sprintf(
                'Падение вывода: %d completion_tokens (порог шума — %d)',
                $profile['output_drop'],
                $profile['output_drop_noise_threshold'],
            );
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array{0: int, 1: int}|null
     */
    private function parseNoisePairOption(string $raw): ?array
    {
        $parts = explode(',', $raw);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1]];
    }
}
