<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Validation;

/**
 * Итог структурной и семантической валидации набора правил парсинга.
 */
final readonly class RuleValidationResult
{
    /**
     * @param  list<RuleValidationIssue>  $issues
     */
    public function __construct(
        public bool $valid,
        public string $schemaId,
        public int $schemaVersion,
        public array $issues = [],
    ) {}

    /**
     * @return array{
     *     valid: bool,
     *     schema_id: string,
     *     schema_version: int,
     *     issues: list<array{severity: string, code: string, path: string, message: string}>,
     * }
     */
    public function toArray(): array
    {
        $issues = [];
        foreach ($this->issues as $issue) {
            $issues[] = $issue->toArray();
        }

        return [
            'valid' => $this->valid,
            'schema_id' => $this->schemaId,
            'schema_version' => $this->schemaVersion,
            'issues' => $issues,
        ];
    }
}
