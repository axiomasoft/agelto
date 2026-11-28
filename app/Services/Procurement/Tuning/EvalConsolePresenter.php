<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Dto\Procurement\Analyzers\Estimate\Diff\DiffEntry;
use App\Dto\Procurement\Analyzers\Estimate\Diff\DiffReport;
use App\Exceptions\Procurement\InvalidEvalDiffPayload;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\EvalRun;
use App\Repositories\Procurement\Ai\EvalRunReadRepository;
use RuntimeException;

/**
 * @phpstan-type EvalAggregate array{f1: float, row_coverage: float, per_field: array<string, float>, examples: int}
 * @phpstan-type EvalSubsample array{base: EvalAggregate, candidate: EvalAggregate, delta: array{f1: float, row_coverage: float, per_field: array<string, float>}}
 * @phpstan-type EvalProfile array{calls: int, prompt_tokens: int, completion_tokens: int, avg_prompt_tokens: float, cost_micros: int}
 * @phpstan-type EvalComparison array{base_run_id: int, candidate_run_id: int, subsamples: array<string, EvalSubsample>, per_fixture: array<string, float>, verdict: 'accept'|'reject', reasons: list<string>, noise_basis?: array{pair_base_id: int, pair_candidate_id: int, subsample_thresholds: array<string, array{f1: float, row_coverage: float}>, per_fixture_threshold: float, per_fixture_worst_code: string}, profile?: array{base: EvalProfile, candidate: EvalProfile, output_drop: int, output_drop_noise_threshold: int}}
 */
final readonly class EvalConsolePresenter
{
    /**
     * @return array{base: EvalRun, candidate: EvalRun, noisePairBase: ?EvalRun, noisePairCandidate: ?EvalRun}
     */
    public function resolveCompareInputs(
        EvalRunReadRepository $evalRuns,
        mixed $baseOption,
        mixed $candidateOption,
        string $criterion,
        mixed $noisePairOption,
    ): array {
        if (! is_scalar($baseOption) || ! is_scalar($candidateOption)) {
            throw new RuntimeException('Нужны --base=<id прогона> и --candidate=<id прогона>.');
        }

        if (! in_array($criterion, [EvalRunComparator::CRITERION_PATCH, EvalRunComparator::CRITERION_NO_REGRESSION, EvalRunComparator::CRITERION_NOISE_AWARE], true)) {
            throw new RuntimeException('Неизвестный --criterion — ожидается patch|no-regression|noise-aware.');
        }

        if ($criterion === EvalRunComparator::CRITERION_NOISE_AWARE && $noisePairOption === null) {
            throw new RuntimeException('Режим noise-aware требует --noise-pair=<id>,<id> — дефолтной нулевой пары нет.');
        }

        $noisePairBase = null;
        $noisePairCandidate = null;

        if ($noisePairOption !== null) {
            $noisePairIds = is_scalar($noisePairOption) ? $this->parseNoisePairOption((string) $noisePairOption) : null;

            if ($noisePairIds === null) {
                throw new RuntimeException('--noise-pair ожидает `<id>,<id>` (два целых через запятую).');
            }

            $noisePairBase = $evalRuns->find($noisePairIds[0]);
            $noisePairCandidate = $evalRuns->find($noisePairIds[1]);

            if ($noisePairBase === null || $noisePairCandidate === null) {
                throw new RuntimeException('EvalRun нулевой пары не найден — проверь --noise-pair.');
            }
        }

        $base = $evalRuns->find((int) $baseOption);
        $candidate = $evalRuns->find((int) $candidateOption);

        if ($base === null || $candidate === null) {
            throw new RuntimeException('EvalRun не найден — проверь --base/--candidate.');
        }

        return [
            'base' => $base,
            'candidate' => $candidate,
            'noisePairBase' => $noisePairBase,
            'noisePairCandidate' => $noisePairCandidate,
        ];
    }

    /**
     * @param  EvalComparison  $result
     */
    public function renderComparisonMarkdown(array $result): string
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
    public function parseNoisePairOption(string $raw): ?array
    {
        $parts = explode(',', $raw);

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return null;
        }

        return [(int) $parts[0], (int) $parts[1]];
    }

    public function renderDiffMarkdown(string $code, Analysis $analysis, DiffReport $report): string
    {
        $lines = [];
        $lines[] = "# Diff-отчёт: {$code} (Analysis #{$analysis->id})";
        $lines[] = '';
        $lines[] = "**Вердикт:** {$report->verdict}";
        $lines[] = '';
        $lines[] = sprintf('Показано строчных расхождений %d из %d.', $report->truncated['shown'], $report->truncated['total']);
        $lines[] = '';

        foreach (['rows' => 'Строки', 'passport' => 'Паспорт', 'totals' => 'Итоги'] as $key => $title) {
            /** @var list<DiffEntry> $entries */
            $entries = $report->{$key};
            $entryCount = count($entries);
            $lines[] = "## {$title} ({$entryCount})";
            $lines[] = '';

            if ($entries === []) {
                $lines[] = 'Расхождений нет.';
                $lines[] = '';

                continue;
            }

            $lines[] = '| смета | строка | вид | поле | gold | предсказание |';
            $lines[] = '|--:|--:|:--|:--|:--|:--|';

            foreach ($entries as $entry) {
                $lines[] = sprintf(
                    '| %d | %s | %s | %s | %s | %s |',
                    $entry->estimateIndex,
                    $entry->rowIndex ?? '—',
                    $entry->kind,
                    $entry->field ?? '—',
                    $this->scalar($entry->gold),
                    $this->scalar($entry->predicted),
                );
            }

            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<array<string, mixed>> */
    public function extractEstimates(mixed $value, string $source): array
    {
        if (! is_array($value)) {
            throw new InvalidEvalDiffPayload("{$source} estimates должен быть списком объектов.");
        }

        $estimates = [];

        foreach ($value as $estimate) {
            if (! is_array($estimate)) {
                throw new InvalidEvalDiffPayload("{$source} estimates должен содержать только объекты.");
            }

            foreach (array_keys($estimate) as $key) {
                if (! is_string($key)) {
                    throw new InvalidEvalDiffPayload("{$source} estimates содержит нестроковый ключ.");
                }
            }

            /** @var array<string, mixed> $estimate */
            $estimates[] = $estimate;
        }

        return $estimates;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—';
    }
}
