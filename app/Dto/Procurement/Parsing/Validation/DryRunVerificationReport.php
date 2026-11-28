<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Validation;

/**
 * Сводный отчёт пробного прогона набора правил парсинга по набору образцов.
 */
final readonly class DryRunVerificationReport
{
    /**
     * @param  list<DryRunSampleReport>  $samples
     * @param  list<string>  $regressions
     */
    public function __construct(
        public bool $passed,
        public int $samplesCount,
        public int $passedCount,
        public array $samples = [],
        public bool $improved = false,
        public array $regressions = [],
    ) {}

    /**
     * @return array{
     *     passed: bool,
     *     improved: bool,
     *     regressions: list<string>,
     *     samples_count: int,
     *     passed_count: int,
     *     samples: list<array{sample_key: string, passed: bool, extracted_count: int, missing_required_fields: list<string>, errors: list<string>}>,
     * }
     */
    public function toArray(): array
    {
        $samples = [];
        foreach ($this->samples as $sample) {
            $samples[] = $sample->toArray();
        }

        return [
            'passed' => $this->passed,
            'improved' => $this->improved,
            'regressions' => $this->regressions,
            'samples_count' => $this->samplesCount,
            'passed_count' => $this->passedCount,
            'samples' => $samples,
        ];
    }
}
