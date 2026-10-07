<?php

declare(strict_types=1);

namespace App\Services\Procurement\Health;

use App\Dto\Procurement\ProcurementDetails;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Procurement;
use App\Services\Procurement\SourceControl\SourceCircuitController;
use App\Services\Procurement\SourceControl\SourceIncidentRecorder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Preflight HTML-карточки закупки ДО разбора: каждая снятая вкладка сверяется с якорями эталона
 * healthcheck (`card_tabs.*.anchors`, `resources/crawler/health/eis-baseline.json`) тем же
 * `CardAnchorProbe`, что считает метрики после разбора. Вкладка без критичного якоря не
 * разбирается: парсер на изменённой вёрстке молча вернул бы пустые поля, а процессоры
 * записали бы их как «данных нет».
 *
 * Почему эталон, а не селекторы парсеров: эталон — уже существующий, версионируемый
 * (sha256 в истории проверок) источник истины о вёрстке; дублировать селекторы в парсерах
 * значило бы завести второй. Вкладки `known_broken` (подгружаются динамически, якорь
 * эталона заведомо не виден в серверном HTML) не проверяются.
 *
 * Отказ preflight — ЛОКАЛЬНЫЙ для карточки: он не трогает брейкер источника, потому что тот
 * управляет обходом листинга (CSV), а CSV при изменении вёрстки карточек продолжает работать.
 * Нужно обсудить: отдельный брейкер для детальной стадии (массовый дрейф вёрстки → пауза
 * только детальной очереди) — сейчас массовость видна по частоте инцидентов в журнале.
 *
 * Шум: один инцидент на (площадка, вкладка) за `crawler.card_preflight.incident_throttle_minutes`;
 * каждое срабатывание пишется в лог.
 */
final readonly class CardLayoutPreflight
{
    public function __construct(
        private HealthBaselineReader $baselines,
        private CardAnchorProbe $probe,
        private SourceIncidentRecorder $incidents,
    ) {}

    /**
     * Вкладки, вёрстка которых не совпала с эталоном: `tab => [селекторы без совпадений]`.
     * Пустой массив — разбирать можно всё. Эталон недоступен — проверка не блокирует разбор
     * (это сбой нашей конфигурации, а не источника) и пишет предупреждение в лог.
     *
     * @return array<string, list<string>>
     */
    public function driftedTabs(Procurement $procurement, ProcurementDetails $details): array
    {
        try {
            $baselineTabs = $this->baselines->read()->cardTabs;
        } catch (Throwable $exception) {
            Log::warning('CardLayoutPreflight: эталон недоступен, preflight карточки пропущен.', ['error' => $exception->getMessage()]);

            return [];
        }

        $drifted = [];

        foreach ($this->tabs(details: $details) as $tab => $html) {
            $description = $baselineTabs[$tab] ?? null;

            if ($html === null || ! is_array($description) || ($description['status'] ?? null) === 'known_broken') {
                continue;
            }

            // Preflight проверяет только структурные якоря. Якоря с `content_dependent: true`
            // зависят от данных закупки (нет вложений, нет участников — D128) и легитимно
            // отсутствуют; по ним дрейф не определить, они остаются только в метриках healthcheck.
            // Нужно обсудить: у вкладки documents сейчас нет структурного якоря (оба якоря —
            // вложения), поэтому её вёрстка до разбора не проверяется; нужен селектор контейнера
            // вкладки, снятый с реальных страниц.
            $anchors = is_array($description['anchors'] ?? null) ? $description['anchors'] : [];
            $structural = array_values(array_filter(
                $anchors,
                static fn (mixed $anchor): bool => ! (is_array($anchor) && ($anchor['content_dependent'] ?? false) === true),
            ));

            if ($structural === []) {
                continue;
            }

            try {
                $outcome = $this->probe->probe(html: $html, tabDescription: ['anchors' => $structural] + $description);
            } catch (Throwable) {
                continue;
            }

            if ($outcome->anchorsMissingCritical === 0) {
                continue;
            }

            $missing = [];

            foreach ($outcome->anchors as $anchor) {
                if ($anchor['critical'] && $anchor['matches'] < 1) {
                    $missing[] = $anchor['selector'];
                }
            }

            // Якорь с `min_matches > 1` может найтись, но в меньшем числе: тогда называем все
            // критичные якоря вкладки, чтобы инцидент не был пустым.
            $drifted[$tab] = $missing !== [] ? $missing : array_values(array_map(
                static fn (array $anchor): string => $anchor['selector'],
                array_filter($outcome->anchors, static fn (array $anchor): bool => $anchor['critical']),
            ));
        }

        if ($drifted !== []) {
            $this->report(procurement: $procurement, drifted: $drifted);
        }

        return $drifted;
    }

    /** @param array<string, list<string>> $drifted */
    private function report(Procurement $procurement, array $drifted): void
    {
        $platformId = $procurement->registry?->platform_id;
        $throttle = config('crawler.card_preflight.incident_throttle_minutes', 10);
        $minutes = is_numeric($throttle) ? max(1, (int) $throttle) : 10;

        foreach ($drifted as $tab => $selectors) {
            $message = "Вкладка {$tab} закупки {$procurement->registry_number}: нет якорей ".implode(', ', $selectors).'. Вкладка не разобрана.';
            Log::warning("CardLayoutPreflight: {$message}", ['procurement_id' => $procurement->id, 'tab' => $tab]);

            if ($platformId === null || ! Cache::add("card_layout_drift:{$platformId}:{$tab}", true, now()->addMinutes($minutes))) {
                continue;
            }

            $this->incidents->record(
                platformId: $platformId,
                type: SourceIncidentType::CardLayoutDrift,
                message: $message,
                context: ['procurement_id' => $procurement->id, 'tab' => $tab, 'missing_selectors' => $selectors],
                actor: SourceCircuitController::SystemActor,
            );
        }
    }

    /** @return array<string, string|null> */
    private function tabs(ProcurementDetails $details): array
    {
        return [
            'common-info' => $details->commonInfoHtml,
            'documents' => $details->documentsHtml,
            'event-journal' => $details->eventJournalHtml,
            'supplier-results' => $details->supplierResultsHtml,
        ];
    }
}
