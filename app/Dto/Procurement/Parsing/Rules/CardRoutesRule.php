<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

/**
 * Правило маршрутов вкладок/эндпоинтов карточки закупки.
 */
final readonly class CardRoutesRule
{
    public function __construct(
        public ?string $commonInfoPath = null,
        public ?string $documentsPath = null,
        public ?string $journalPath = null,
        public ?string $supplierResultsPath = null,
        public ?string $lotPath = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            commonInfoPath: RuleData::nullableString($data, 'common_info_path'),
            documentsPath: RuleData::nullableString($data, 'documents_path'),
            journalPath: RuleData::nullableString($data, 'journal_path'),
            supplierResultsPath: RuleData::nullableString($data, 'supplier_results_path'),
            lotPath: RuleData::nullableString($data, 'lot_path'),
        );
    }

    /**
     * @return array{
     *     common_info_path: string|null,
     *     documents_path: string|null,
     *     journal_path: string|null,
     *     supplier_results_path: string|null,
     *     lot_path: string|null,
     * }
     */
    public function toArray(): array
    {
        return [
            'common_info_path' => $this->commonInfoPath,
            'documents_path' => $this->documentsPath,
            'journal_path' => $this->journalPath,
            'supplier_results_path' => $this->supplierResultsPath,
            'lot_path' => $this->lotPath,
        ];
    }
}
