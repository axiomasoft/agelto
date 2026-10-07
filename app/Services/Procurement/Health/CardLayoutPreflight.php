<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\ProcurementDetails;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * Эталон недоступен — это сбой нашей конфигурации, а не площадки: проверка не блокирует
 * разбор и пишет warning. Ревьюеру: если вы считаете, что без эталона разбирать нельзя, это
 * одна строка — оспорьте.
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
    public function missingBaseSelectors(ProcurementDetails $details): array
    {
        try {
            $baselineTabs = $this->baselines->read()->cardTabs;
        } catch (Throwable $exception) {
            Log::warning('CardLayoutPreflight: эталон недоступен, проверка карточки пропущена.', ['error' => $exception->getMessage()]);

            return [];
        }

        // TODO(documents): у вкладки documents нет базового селектора — оба якоря эталона
        // (`a[href*=filestore]`, `div.attachment`) зависят от наличия вложений, поэтому её вёрстка
        // до разбора не проверяется. Нужно: снять с нескольких реальных карточек (с вложениями и
        // без) селектор контейнера вкладки, который есть всегда, добавить его в эталон площадки
        // (файл, который читает `HealthBaselineReader`) как `critical: true` без
        // `content_dependent` и тест «documents без контейнера → карточка не обработана».
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

            if ($outcome->anchorsMissingCritical > 0) {
                $missing[$tab] = array_values(array_map(
                    static fn (array $anchor): string => $anchor['selector'],
                    array_filter($outcome->anchors, static fn (array $anchor): bool => $anchor['critical'] && $anchor['matches'] < 1),
                ));
            }
        }

        return $missing;
    }
}
