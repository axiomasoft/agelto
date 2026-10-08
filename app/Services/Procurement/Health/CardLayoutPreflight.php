<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\Health\HealthBaseline;
use App\Dto\Procurement\ProcurementDetails;
use App\Exceptions\Procurement\Health\HealthBaselineUnavailableException;

/**
 * Проверка HTML-карточки ДО разбора: на каждой снятой вкладке должны быть базовые селекторы
 * эталона (`card_tabs.*.anchors` из `HealthBaselineReader`). Базовые — критичные якоря без
 * `content_dependent`: они есть на любой странице закупки. Якоря, зависящие от данных
 * (вложения, таблица участников), легитимно отсутствуют и в проверку не входят. Вкладки
 * `known_broken` и неснятые вкладки не проверяются.
 *
 * Почему эталон, а не селекторы парсеров: эталон уже версионируется и используется для метрик
 * healthcheck — второй список селекторов разошёлся бы с ним.
 *
 * Недоступный эталон — ошибка конфигурации. Исключение уходит в штатный повтор карточки:
 * без проверки структуры нельзя подтверждать успешный разбор и перезаписывать данные.
 */
final readonly class CardLayoutPreflight
{
    public function __construct(
        private HealthBaselineReader $baselines,
        private CardAnchorProbe $probe,
    ) {}

    /**
     * Вкладки без базовых селекторов: `tab => [селекторы]`. Пусто — страницу можно разбирать.
     *
     * @return array<string, list<string>>
     */
    public function missingBaseSelectors(ProcurementDetails $details, ?HealthBaseline $baseline = null): array
    {
        $baselineTabs = ($baseline ?? $this->baselines->read())->cardTabs;

        $missing = [];

        foreach ([
            'common-info' => $details->commonInfoHtml,
            'documents' => $details->documentsHtml,
            'event-journal' => $details->eventJournalHtml,
            'supplier-results' => $details->supplierResultsHtml,
        ] as $tab => $html) {
            $description = $baselineTabs[$tab] ?? null;

            if ($html === null || ! is_array($description) || ($description['status'] ?? null) === 'known_broken') {
                continue;
            }

            $base = array_values(array_filter(
                is_array($description['anchors'] ?? null) ? $description['anchors'] : [],
                static fn (mixed $anchor): bool => is_array($anchor) && ($anchor['content_dependent'] ?? false) !== true,
            ));

            if ($base === []) {
                continue;
            }

            $outcome = $this->probe->probe(html: $html, tabDescription: ['anchors' => $base]);

            if ($outcome->status === 'probe_failed') {
                throw new HealthBaselineUnavailableException("не удалось проверить селекторы вкладки {$tab}");
            }

            if ($outcome->anchorsMissingCritical > 0) {
                $missing[$tab] = array_values(array_map(
                    static fn (array $anchor): string => $anchor['selector'],
                    array_filter($outcome->anchors, static fn (array $anchor): bool => $anchor['critical'] && $anchor['matches'] < ($anchor['min_matches'] ?? 1)),
                ));
            }
        }

        return $missing;
    }
}
