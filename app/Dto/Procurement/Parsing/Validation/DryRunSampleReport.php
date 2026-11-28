<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Validation;

/**
 * Результат пробного прогона набора правил на одном эталонном образце.
 */
final readonly class DryRunSampleReport
{
    /**
     * @param  list<string>  $missingRequiredFields
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $sampleKey,
        public bool $passed,
        public int $extractedCount = 0,
        public array $missingRequiredFields = [],
        public array $errors = [],
    ) {}

    /**
     * @return array{
     *     sample_key: string,
     *     passed: bool,
     *     extracted_count: int,
     *     missing_required_fields: list<string>,
     *     errors: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'sample_key' => $this->sampleKey,
            'passed' => $this->passed,
            'extracted_count' => $this->extractedCount,
            'missing_required_fields' => $this->missingRequiredFields,
            'errors' => $this->errors,
        ];
    }
}
