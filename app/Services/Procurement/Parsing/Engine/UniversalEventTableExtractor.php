<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Primitives\EventClassificationRule;
use App\Dto\Procurement\Parsing\Rules\EventJournalRule;
use App\Services\Procurement\Parsing\Validation\SafeRegex;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use App\Services\Procurement\Platform\Contracts\EventTableExtractor;
use DOMXPath;

/**
 * Универсальный экстрактор и классификатор журнала событий карточки закупки.
 */
final class UniversalEventTableExtractor implements EventTableExtractor
{
    public function __construct(
        private readonly DomExtractor $dom,
    ) {}

    /**
     * @return list<array{
     *     date: string|null,
     *     description: string,
     *     event_type: string|null,
     * }>
     */
    public function extract(DOMXPath $xpath, EventJournalRule $rule): array
    {
        if ($rule->rowSelectors === null || $rule->descriptionSelectors === null) {
            return [];
        }

        $classifications = $rule->classifications;
        usort(
            $classifications,
            static fn (EventClassificationRule $a, EventClassificationRule $b): int => $b->priority <=> $a->priority,
        );

        $rows = $this->dom->queryAll($xpath, $rule->rowSelectors);
        $events = [];

        foreach ($rows as $rowNode) {
            $description = $this->dom->firstText($xpath, $rule->descriptionSelectors, $rowNode);
            if ($description === null) {
                continue;
            }

            $date = $rule->dateSelectors !== null
                ? $this->dom->firstText($xpath, $rule->dateSelectors, $rowNode)
                : null;

            $events[] = [
                'date' => $date,
                'description' => $description,
                'event_type' => $this->classify($description, $classifications),
            ];
        }

        return $events;
    }

    /**
     * @param  list<EventClassificationRule>  $classifications
     */
    private function classify(string $description, array $classifications): ?string
    {
        foreach ($classifications as $classification) {
            $matchedKeyword = false;
            foreach ($classification->keywords as $keyword) {
                if (mb_stripos($description, $keyword) !== false) {
                    $matchedKeyword = true;
                    break;
                }
            }

            $matchedPattern = false;
            if ($classification->pattern !== null) {
                $matches = [];
                $matchedPattern = SafeRegex::match($classification->pattern, $description, $matches);
            }

            if ($matchedKeyword || $matchedPattern) {
                return $classification->eventType->value;
            }
        }

        return null;
    }
}
