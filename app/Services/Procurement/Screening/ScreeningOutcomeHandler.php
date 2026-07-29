<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\AnalysisStatus;
use App\Enums\Procurement\DocumentStatus;
use App\Enums\Procurement\LlmPurpose;
use App\Enums\Procurement\ProcurementEventType;
use App\Jobs\Procurement\EstimateExtractionJob;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Repositories\Procurement\AnalysisRunStoreRepository;
use App\Repositories\Procurement\AnalysisStoreRepository;
use App\Repositories\Procurement\DocumentStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Llm\AiRouteResolver;

/**
 * Ветвление скрининга (ТЗ 03 §3.4/§6, D36): `estimate` → `screened` + dispatch извлечения
 * (заглушка до P6.1, гейт `config('ai.extraction.enabled')`); иной известный тип → `screened`
 * без разбора; `unknown` или совпадение с `ai.screening.ignore_rules` (подстрока в
 * `Procurement.title`, v1-канон) → `ignored`. Borderline-зона confidence (D36) НЕ уходит молча
 * в `ignored`: `ai.screening.borderline_action` = `escalate` (один повторный скрининг на маршрут
 * классом выше, `AiRouteResolver`-индекс 1, флаг `escalated` в `analysis.result` защищает от
 * второго прогона — не бесконечный цикл, счётчик как в `ReaskPolicy`) | `flag` (default,
 * `needs_review=true` в `analysis.result`, статус `screened`, экстракция НЕ диспатчится —
 * оператор решает вручную). Идемпотентно: повторный вызов на уже НЕ `prepared` документе — no-op.
 */
final readonly class ScreeningOutcomeHandler
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private ProcurementStoreRepository $procurements,
        private ScreeningProcessor $processor,
        private AiRouteResolver $routes,
    ) {}

    public function handle(Document $document, Analysis $analysis): void
    {
        if ($document->status !== DocumentStatus::Prepared) {
            // Уже проведено ветвление (screened/ignored) либо документ ушёл в unprocessable —
            // повторный вызов (backlog-поллер, ручной ретрай) — no-op.
            return;
        }

        if ($analysis->status !== AnalysisStatus::Succeeded) {
            // unprocessable/failed — веткование не наше дело, тут нечего мапить.
            return;
        }

        $this->resolve($document, $analysis);
    }

    private function resolve(Document $document, Analysis $analysis): void
    {
        if ($this->matchesIgnoreRule($document)) {
            $this->ignore($document, $analysis, reason: 'ignore_rule');

            return;
        }

        $result = $analysis->result;
        $confidence = (float) ($result['confidence'] ?? 0.0);

        $low = (float) config('ai.screening.confidence_low', 0.4);
        $high = (float) config('ai.screening.confidence_high', 0.6);

        if ($confidence < $low) {
            $this->ignore($document, $analysis, reason: 'low_confidence');

            return;
        }

        if ($confidence <= $high) {
            $this->handleBorderline($document, $analysis);

            return;
        }

        $documentType = $analysis->document_type !== null ? AiDocumentType::tryFrom($analysis->document_type) : null;

        $this->branchByType($document, $analysis, $documentType);
    }

    private function handleBorderline(Document $document, Analysis $analysis): void
    {
        $action = (string) config('ai.screening.borderline_action', 'flag');
        $alreadyEscalated = (bool) ($analysis->result['escalated'] ?? false);

        if ($action === 'escalate' && ! $alreadyEscalated) {
            $escalationRoute = $this->routes->candidatesFor('any', LlmPurpose::Screening)->values()->get(1);

            if ($escalationRoute !== null) {
                $this->processor->run($document, $analysis, routeIndex: 1);
                $analysis->refresh();

                $this->analyses->update($analysis, [
                    'result' => [...$analysis->result->toArray(), 'escalated' => true],
                ]);
                $analysis->refresh();

                $this->resolve($document, $analysis);

                return;
            }
        }

        $this->flagNeedsReview($document, $analysis);
    }

    private function branchByType(Document $document, Analysis $analysis, ?AiDocumentType $documentType): void
    {
        match ($documentType) {
            AiDocumentType::Estimate => $this->markScreened($document, $analysis, dispatchExtraction: true),
            AiDocumentType::Unknown, null => $this->ignore($document, $analysis, reason: 'unknown'),
            default => $this->markScreened($document, $analysis, dispatchExtraction: false),
        };
    }

    private function markScreened(Document $document, Analysis $analysis, bool $dispatchExtraction): void
    {
        $this->documents->update($document, ['status' => DocumentStatus::Screened]);

        $this->storeEvent($document, $analysis, outcome: $dispatchExtraction ? 'estimate' : 'screened');

        $extractionDispatched = $dispatchExtraction && config('ai.extraction.enabled', false);

        if ($extractionDispatched) {
            EstimateExtractionJob::dispatch($document->id)->afterCommit();

            return;
        }

        // Прогон терминируется ЗДЕСЬ (не автохуком `AnalysisStoreRepository::update()`, D93
        // §3.2): анализ уже `Succeeded` до вызова этого хендлера, и статус screening'а НЕ
        // определяет исход прогона — тип «не смета» либо гейт `ai.extraction.enabled` выключен,
        // извлечение не следует, контур прогона заканчивается на этой ветке.
        $this->runs->markSucceeded($analysis->analysisRun);
    }

    private function flagNeedsReview(Document $document, Analysis $analysis): void
    {
        $this->documents->update($document, ['status' => DocumentStatus::Screened]);

        $this->analyses->update($analysis, [
            'result' => [...$analysis->result->toArray(), 'needs_review' => true],
        ]);

        $this->storeEvent($document, $analysis, outcome: 'needs_review');

        $this->runs->markSucceeded($analysis->analysisRun);
    }

    private function ignore(Document $document, Analysis $analysis, string $reason): void
    {
        $this->documents->update($document, ['status' => DocumentStatus::Ignored]);

        $this->analyses->update($analysis, [
            'result' => [...$analysis->result->toArray(), 'branch_reason' => $reason],
        ]);

        $this->storeEvent($document, $analysis, outcome: 'ignored');

        $this->runs->markSucceeded($analysis->analysisRun);
    }

    private function storeEvent(Document $document, Analysis $analysis, string $outcome): void
    {
        if ($document->procurement_id === null) {
            // Manual-импорт/документ вне закупки (D22) — журнала закупки нет, писать некуда.
            return;
        }

        $this->procurements->storeEvent(
            procurementId: $document->procurement_id,
            eventType: ProcurementEventType::DocumentScreened,
            occurredAt: now(),
            entityRef: ['document_id' => $document->id, 'analysis_id' => $analysis->id],
            newValue: $outcome,
            payload: [
                'document_type' => $analysis->document_type,
                'confidence' => $analysis->result['confidence'] ?? null,
            ],
        );
    }

    /**
     * Подстрока из `ai.screening.ignore_rules` в `Procurement.title` (v1-канон
     * `config('platform.estimate.ignore_rules')`, например «Поставка продуктов питания») —
     * категорический стоп независимо от того, что классифицировала модель.
     */
    private function matchesIgnoreRule(Document $document): bool
    {
        if ($document->procurement_id === null) {
            return false;
        }

        $rules = (array) config('ai.screening.ignore_rules', []);

        if ($rules === []) {
            return false;
        }

        $title = mb_strtolower((string) Procurement::query()->find($document->procurement_id)?->title);

        if ($title === '') {
            return false;
        }

        foreach ($rules as $rule) {
            if (str_contains($title, mb_strtolower((string) $rule))) {
                return true;
            }
        }

        return false;
    }
}
