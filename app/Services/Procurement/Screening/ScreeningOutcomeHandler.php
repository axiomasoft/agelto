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
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Procurement\Contracts\DocumentAnalyzer;
use App\Services\Procurement\Contracts\DocumentAnalyzerRegistry;

/** Computes the backend-owned D34 decision; persistence belongs only to the Action. */
final readonly class ScreeningOutcomeHandler
{
    public function __construct(
        private ApplyScreeningDispositionAction $applyDisposition,
        private AnalysisStoreRepository $analyses,
        private LlmRouteResolver $routes,
        private DocumentAnalyzerRegistry $analyzers,
    ) {}

    public function handle(Document $document, Analysis $analysis, ScreeningResult $result, bool $escalated = false): bool
    {
        if ($analysis->status !== AnalysisStatus::Running) {
            return false;
        }

        if (! $analysis->is_shadow && $document->status !== DocumentStatus::Prepared) {
            return false;
        }

        if ($this->isBorderline($result) && ! $escalated && $this->canEscalate()) {
            $progress = $analysis->progress?->all() ?? [];
            $progress['screening_route_index'] = 1;
            $this->analyses->update($analysis, ['progress' => $progress]);

            return true;
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

        return false;
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

        $low = $this->floatConfig(key: 'ai.screening.confidence_low', default: 0.4);
        $high = $this->floatConfig(key: 'ai.screening.confidence_high', default: 0.6);

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
        return $result->confidence >= $this->floatConfig(key: 'ai.screening.confidence_low', default: 0.4)
            && $result->confidence <= $this->floatConfig(key: 'ai.screening.confidence_high', default: 0.6);
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
            if (is_string($rule) && $title !== '' && str_contains($title, mb_strtolower($rule))) {
                return true;
            }
        }

        return false;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = config($key, $default);

        if (! is_float($value) && ! is_int($value)) {
            throw new \RuntimeException("{$key} must be a number.");
        }

        return (float) $value;
    }
}
