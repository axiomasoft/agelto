<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Actions\Procurement\Screening\ApplyScreeningDispositionAction;
use App\Dto\Procurement\Ai\Screening\ScreeningDecision;
use App\Dto\Procurement\Ai\Screening\ScreeningResult;
use App\Enums\Llm\CoreLlmPurpose;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Procurement;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;

/** Computes the backend-owned D34 decision; persistence belongs only to the Action. */
final readonly class ScreeningOutcomeHandler
{
    public function __construct(
        private ApplyScreeningDispositionAction $applyDisposition,
        private ScreeningProcessor $processor,
        private LlmRouteResolver $routes,
        private DocumentAnalyzerRegistry $analyzers,
    ) {}

    public function handle(Document $document, Analysis $analysis, ScreeningResult $result, bool $escalated = false): void
    {
        if ($analysis->status !== AnalysisStatus::Running) {
            return;
        }

        if (! $analysis->is_shadow && $document->status !== DocumentStatus::Prepared) {
            return;
        }

        if ($this->isBorderline($result) && ! $escalated && $this->canEscalate()) {
            $replacement = $this->processor->run(document: $document, analysis: $analysis, routeIndex: 1);

            if ($replacement !== null) {
                $analysis->refresh();
                $this->handle($document, $analysis, $replacement, escalated: true);
            }

            return;
        }

        [$decision, $analyzer] = $this->decisionFor($document, $result);

        if ($analysis->is_shadow) {
            $decision = new ScreeningDecision(
                disposition: $decision->disposition,
                reason: $decision->reason,
                extractionDispatched: false,
            );
            $analyzer = null;
        }

        $this->applyDisposition->execute(
            document: $document,
            analysis: $analysis,
            result: $result,
            decision: $decision,
            analyzer: $analyzer,
        );
    }

    /** @return array{ScreeningDecision, DocumentAnalyzer|null} */
    private function decisionFor(Document $document, ScreeningResult $result): array
    {
        if ($this->matchesIgnoreRule($document)) {
            return [new ScreeningDecision(
                disposition: ScreeningDisposition::Ignore,
                reason: ScreeningDecisionReason::IgnoreRule,
                extractionDispatched: false,
            ), null];
        }

        $low = (float) config('ai.screening.confidence_low', 0.4);
        $high = (float) config('ai.screening.confidence_high', 0.6);

        if ($result->confidence < $low) {
            return [new ScreeningDecision(
                disposition: ScreeningDisposition::Ignore,
                reason: ScreeningDecisionReason::LowConfidence,
                extractionDispatched: false,
            ), null];
        }

        if ($result->confidence <= $high) {
            return [new ScreeningDecision(
                disposition: ScreeningDisposition::Review,
                reason: ScreeningDecisionReason::BorderlineConfidence,
                extractionDispatched: false,
            ), null];
        }

        if ($result->document_type === AiDocumentType::Unknown) {
            return [new ScreeningDecision(
                disposition: ScreeningDisposition::Ignore,
                reason: ScreeningDecisionReason::UnknownType,
                extractionDispatched: false,
            ), null];
        }

        $analyzer = $this->analyzers->for($result->document_type);

        if ($analyzer?->hasExtraction() !== true) {
            return [new ScreeningDecision(
                disposition: ScreeningDisposition::Retain,
                reason: ScreeningDecisionReason::KnownTypeNoAnalyzer,
                extractionDispatched: false,
            ), null];
        }

        return [
            new ScreeningDecision(
                disposition: ScreeningDisposition::Extract,
                reason: ScreeningDecisionReason::AnalyzerAvailable,
                extractionDispatched: (bool) config('ai.extraction.enabled', false),
            ),
            $analyzer,
        ];
    }

    private function isBorderline(ScreeningResult $result): bool
    {
        return $result->confidence >= (float) config('ai.screening.confidence_low', 0.4)
            && $result->confidence <= (float) config('ai.screening.confidence_high', 0.6);
    }

    private function canEscalate(): bool
    {
        return config('ai.screening.borderline_action', 'flag') === 'escalate'
            && $this->routes->candidatesFor(DocumentTypeScope::Any, CoreLlmPurpose::Screening)->count() > 1;
    }

    private function matchesIgnoreRule(Document $document): bool
    {
        if ($document->procurement_id === null) {
            return false;
        }

        $rules = (array) config('ai.screening.ignore_rules', []);
        $title = mb_strtolower((string) Procurement::query()->find($document->procurement_id)?->title);

        foreach ($rules as $rule) {
            if ($title !== '' && str_contains($title, mb_strtolower((string) $rule))) {
                return true;
            }
        }

        return false;
    }
}
