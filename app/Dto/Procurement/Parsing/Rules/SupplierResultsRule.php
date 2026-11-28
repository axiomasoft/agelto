<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Rules;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;

/**
 * Правило извлечения сведений об участниках и результатах определения поставщика.
 */
final readonly class SupplierResultsRule
{
    /**
     * @param  list<string>  $winnerMarkers
     */
    public function __construct(
        public ?SelectorChain $containerSelectors = null,
        public ?SelectorChain $participantRowSelectors = null,
        public ?SelectorChain $nameSelectors = null,
        public ?SelectorChain $innSelectors = null,
        public ?SelectorChain $priceSelectors = null,
        public ?SelectorChain $statusSelectors = null,
        public array $winnerMarkers = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            containerSelectors: RuleData::nullableSelectorChain($data, 'container_selectors'),
            participantRowSelectors: RuleData::nullableSelectorChain($data, 'participant_row_selectors'),
            nameSelectors: RuleData::nullableSelectorChain($data, 'name_selectors'),
            innSelectors: RuleData::nullableSelectorChain($data, 'inn_selectors'),
            priceSelectors: RuleData::nullableSelectorChain($data, 'price_selectors'),
            statusSelectors: RuleData::nullableSelectorChain($data, 'status_selectors'),
            winnerMarkers: RuleData::stringList($data, 'winner_markers'),
        );
    }

    /**
     * @return array{
     *     container_selectors: non-empty-list<string>|null,
     *     participant_row_selectors: non-empty-list<string>|null,
     *     name_selectors: non-empty-list<string>|null,
     *     inn_selectors: non-empty-list<string>|null,
     *     price_selectors: non-empty-list<string>|null,
     *     status_selectors: non-empty-list<string>|null,
     *     winner_markers: list<string>,
     * }
     */
    public function toArray(): array
    {
        return [
            'container_selectors' => $this->containerSelectors?->toArray(),
            'participant_row_selectors' => $this->participantRowSelectors?->toArray(),
            'name_selectors' => $this->nameSelectors?->toArray(),
            'inn_selectors' => $this->innSelectors?->toArray(),
            'price_selectors' => $this->priceSelectors?->toArray(),
            'status_selectors' => $this->statusSelectors?->toArray(),
            'winner_markers' => $this->winnerMarkers,
        ];
    }
}
