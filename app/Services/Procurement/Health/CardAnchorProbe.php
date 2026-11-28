<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\CardParseOutcome;
use App\Dto\Procurement\Parsing\Rules\DetailRuleSetDto;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Второй DOM-проход по снятой вкладке нужен, чтобы отделить дрейф вёрстки от пустого результата
 * уже выполненного парсинга. Зонд намеренно не знает о закупках и БД; любая его ошибка становится
 * `probe_failed`, а не отказом снятия карточки.
 */
final readonly class CardAnchorProbe
{
    /**
     * @param  array<string, mixed>  $tabDescription
     * @param  array<string, mixed>  $keyFieldValues
     */
    public function probe(
        ?string $html,
        array $tabDescription,
        array $keyFieldValues = [],
        ?DetailRuleSetDto $ruleSet = null,
        ?string $tabName = null,
    ): CardParseOutcome {
        $keyFields = $this->keyFields($tabDescription);

        if ($html === null) {
            return new CardParseOutcome(
                status: 'tab_absent',
                anchors: [],
                anchorsMissingCritical: 0,
                keyFieldsExpected: count($keyFields),
                keyFieldsFilled: $this->filledKeyFields($keyFields, $keyFieldValues),
            );
        }

        try {
            $crawler = new Crawler($html);
            $anchors = [];
            $anchorsMissingCritical = 0;

            $anchorDefs = ($ruleSet !== null && $tabName !== null)
                ? (($this->anchorsFromRuleSet($ruleSet, $tabName)) ?: $this->anchors($tabDescription))
                : $this->anchors($tabDescription);

            foreach ($anchorDefs as $anchor) {
                $matches = $crawler->filter($anchor['selector'])->count();

                if ($anchor['critical'] && $matches < $anchor['min_matches']) {
                    $anchorsMissingCritical++;
                }

                $anchors[] = [
                    'selector' => $anchor['selector'],
                    'critical' => $anchor['critical'],
                    'matches' => $matches,
                    'min_matches' => $anchor['min_matches'],
                ];
            }

            return new CardParseOutcome(
                status: ($tabDescription['status'] ?? null) === 'known_broken' ? 'known_broken' : 'measured',
                anchors: $anchors,
                anchorsMissingCritical: $anchorsMissingCritical,
                keyFieldsExpected: count($keyFields),
                keyFieldsFilled: $this->filledKeyFields($keyFields, $keyFieldValues),
            );
        } catch (Throwable) {
            return $this->probeFailed($tabDescription, $keyFieldValues);
        }
    }

    /**
     * @param  array<string, mixed>  $tabDescription
     * @param  array<string, mixed>  $keyFieldValues
     */
    public function probeFailed(array $tabDescription = [], array $keyFieldValues = []): CardParseOutcome
    {
        $keyFields = $this->keyFields($tabDescription);

        return new CardParseOutcome(
            status: 'probe_failed',
            anchors: [],
            anchorsMissingCritical: 0,
            keyFieldsExpected: count($keyFields),
            keyFieldsFilled: $this->filledKeyFields($keyFields, $keyFieldValues),
        );
    }

    /**
     * @return list<array{selector: string, critical: bool, min_matches: int}>
     */
    public function anchorsFromRuleSet(DetailRuleSetDto $ruleSet, string $tab): array
    {
        $rawSelectors = match ($tab) {
            'common-info', 'common_info' => [
                $ruleSet->envelope?->numberSelectors?->selectors[0]->selector,
                $ruleSet->envelope?->titleSelectors?->selectors[0]->selector,
                $ruleSet->envelope?->statusSelectors?->selectors[0]->selector,
                $ruleSet->parties?->customerSectionSelectors?->selectors[0]->selector,
            ],
            'documents' => [
                $ruleSet->envelope?->numberSelectors?->selectors[0]->selector,
                $ruleSet->documents?->containerSelectors?->selectors[0]->selector,
            ],
            'event-journal', 'event_journal' => [
                $ruleSet->envelope?->numberSelectors?->selectors[0]->selector,
                $ruleSet->eventJournal?->rowSelectors?->selectors[0]->selector,
            ],
            'supplier-results', 'supplier_results' => [
                $ruleSet->envelope?->numberSelectors?->selectors[0]->selector,
                $ruleSet->supplierResults?->containerSelectors?->selectors[0]->selector,
            ],
            default => [],
        };

        $selectors = array_values(array_filter(
            $rawSelectors,
            static fn (?string $s): bool => $s !== null && $s !== '',
        ));

        return array_map(
            static fn (string $selector): array => [
                'selector' => $selector,
                'critical' => true,
                'min_matches' => 1,
            ],
            $selectors,
        );
    }

    /** @param array<string, mixed> $tabDescription
     *  @return list<array{selector: string, critical: bool, min_matches: int}> */
    private function anchors(array $tabDescription): array
    {
        $anchors = $tabDescription['anchors'] ?? null;

        if (! is_array($anchors)) {
            throw new InvalidArgumentException('В эталоне вкладки отсутствует массив anchors.');
        }

        return array_values(array_map(function (mixed $anchor): array {
            if (! is_array($anchor) || ! is_string($anchor['selector'] ?? null) || ! is_bool($anchor['critical'] ?? null)) {
                throw new InvalidArgumentException('Якорь эталона имеет неверную структуру.');
            }

            $minMatches = $anchor['min_matches'] ?? 1;

            if (! is_int($minMatches) || $minMatches < 1) {
                throw new InvalidArgumentException('min_matches должен быть положительным целым числом.');
            }

            return [
                'selector' => $anchor['selector'],
                'critical' => $anchor['critical'],
                'min_matches' => $minMatches,
            ];
        }, $anchors));
    }

    /** @param array<string, mixed> $tabDescription
     *  @return list<string> */
    private function keyFields(array $tabDescription): array
    {
        $keyFields = $tabDescription['key_fields'] ?? [];

        if (! is_array($keyFields)) {
            return [];
        }

        return array_values(array_filter($keyFields, is_string(...)));
    }

    /**
     * @param  list<string>  $keyFields
     * @param  array<string, mixed>  $keyFieldValues
     */
    private function filledKeyFields(array $keyFields, array $keyFieldValues): int
    {
        return count(array_filter(
            $keyFields,
            fn (string $key): bool => $this->isFilled($keyFieldValues[$key] ?? null),
        ));
    }

    private function isFilled(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
