<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Enums\Procurement\Ai\ScreeningDecisionReason;
use App\Enums\Procurement\Ai\ScreeningDisposition;
use InvalidArgumentException;

final readonly class StoredScreeningResult
{
    private const int SchemaVersion = 2;

    public function __construct(
        public float $confidence,
        public string $summary,
        public EstimateCardinality $estimateCardinality,
        /** @var list<string> */
        public array $tags,
        public ?string $classificationNote,
        public ?int $pageCountEstimate,
        public ScreeningDecision $decision,
    ) {}

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromArray(array $value): self
    {
        if (($value['schema_version'] ?? null) !== self::SchemaVersion) {
            throw new InvalidArgumentException('Stored screening result must have schema_version=2.');
        }

        $screening = $value['screening'] ?? null;
        $decision = $value['decision'] ?? null;

        if (! is_array($screening) || ! is_array($decision)) {
            throw new InvalidArgumentException('Stored screening result must contain screening and decision objects.');
        }

        /** @var array<string, mixed> $screening */
        /** @var array<string, mixed> $decision */

        return self::fromParts(
            confidence: $screening['confidence'] ?? null,
            summary: $screening['summary'] ?? null,
            estimateCardinality: $screening['estimate_cardinality'] ?? null,
            tags: $screening['tags'] ?? null,
            classificationNote: $screening['classification_note'] ?? null,
            pageCountEstimate: $screening['page_count_estimate'] ?? null,
            decision: ScreeningDecision::fromArray($decision),
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function fromLegacy(string $documentType, array $value): self
    {
        self::assertOnlyLegacyKeys($value);

        $type = AiDocumentType::tryFrom($documentType);

        if ($type === null) {
            throw new InvalidArgumentException('Legacy screening result has an unknown document type.');
        }

        $decision = self::legacyDecision(type: $type, value: $value);

        return self::fromParts(
            confidence: $value['confidence'] ?? null,
            summary: $value['description'] ?? null,
            estimateCardinality: self::legacyCardinality($type),
            tags: $value['tags'] ?? null,
            classificationNote: $value['ignore_reason'] ?? null,
            pageCountEstimate: $value['page_count_estimate'] ?? null,
            decision: $decision,
        );
    }

    /**
     * @return array{schema_version: 2, screening: array{confidence: float, summary: string, estimate_cardinality: string, tags: list<string>, classification_note: ?string, page_count_estimate: ?int}, decision: array{disposition: string, reason: string, extraction_dispatched: bool}}
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SchemaVersion,
            'screening' => [
                'confidence' => $this->confidence,
                'summary' => $this->summary,
                'estimate_cardinality' => $this->estimateCardinality->value,
                'tags' => $this->tags,
                'classification_note' => $this->classificationNote,
                'page_count_estimate' => $this->pageCountEstimate,
            ],
            'decision' => $this->decision->toArray(),
        ];
    }

    /**
     * @return array{confidence: float, description: string, tags: list<string>, page_count_estimate: ?int, ignore_reason: ?string, extraction_dispatched: bool, needs_review?: true, branch_reason?: string}
     */
    public function toLegacyArray(): array
    {
        $value = [
            'confidence' => $this->confidence,
            'description' => $this->summary,
            'tags' => $this->tags,
            'page_count_estimate' => $this->pageCountEstimate,
            'ignore_reason' => $this->classificationNote,
            'extraction_dispatched' => $this->decision->extractionDispatched,
        ];

        if ($this->decision->reason === ScreeningDecisionReason::BorderlineConfidence) {
            $value['needs_review'] = true;

            return $value;
        }

        $branchReason = match ($this->decision->reason) {
            ScreeningDecisionReason::IgnoreRule => 'ignore_rule',
            ScreeningDecisionReason::LowConfidence => 'low_confidence',
            ScreeningDecisionReason::UnknownType => 'unknown',
            ScreeningDecisionReason::KnownTypeNoAnalyzer => 'known_type_no_analyzer',
            default => 'analyzer_available',
        };

        $value['branch_reason'] = $branchReason;

        return $value;
    }

    private static function fromParts(
        mixed $confidence,
        mixed $summary,
        mixed $estimateCardinality,
        mixed $tags,
        mixed $classificationNote,
        mixed $pageCountEstimate,
        ScreeningDecision $decision,
    ): self {
        if (! is_int($confidence) && ! is_float($confidence)) {
            throw new InvalidArgumentException('Stored screening confidence must be numeric.');
        }

        $typedConfidence = (float) $confidence;

        if (! is_finite($typedConfidence) || $typedConfidence < 0 || $typedConfidence > 1) {
            throw new InvalidArgumentException('Stored screening confidence must be finite and in [0, 1].');
        }

        if (! is_string($summary)) {
            throw new InvalidArgumentException('Stored screening summary is required.');
        }

        $typedCardinality = $estimateCardinality instanceof EstimateCardinality
            ? $estimateCardinality
            : (is_string($estimateCardinality) ? EstimateCardinality::tryFrom($estimateCardinality) : null);

        if ($typedCardinality === null) {
            throw new InvalidArgumentException('Stored screening estimate cardinality is invalid.');
        }

        $typedSummary = ScreeningValueNormalizer::requiredText($summary, maximumLength: 1000, field: 'summary');
        $typedTags = ScreeningValueNormalizer::tags($tags);
        $typedNote = ScreeningValueNormalizer::optionalText($classificationNote, maximumLength: 500, field: 'classification note');
        $typedPageCount = self::normalizePageCount($pageCountEstimate);

        return new self(
            confidence: $typedConfidence,
            summary: $typedSummary,
            estimateCardinality: $typedCardinality,
            tags: $typedTags,
            classificationNote: $typedNote,
            pageCountEstimate: $typedPageCount,
            decision: $decision,
        );
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function assertOnlyLegacyKeys(array $value): void
    {
        $allowed = [
            'confidence',
            'description',
            'tags',
            'page_count_estimate',
            'ignore_reason',
            'needs_review',
            'branch_reason',
            'extraction_dispatched',
        ];

        $unknown = array_diff(array_keys($value), $allowed);

        if ($unknown !== []) {
            throw new InvalidArgumentException('Legacy screening result has unmappable keys: '.implode(', ', $unknown).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function legacyDecision(AiDocumentType $type, array $value): ScreeningDecision
    {
        $extractionDispatched = $value['extraction_dispatched'] ?? null;

        if (! is_bool($extractionDispatched)) {
            throw new InvalidArgumentException('Legacy screening result must preserve extraction_dispatched as a boolean.');
        }

        $branchReason = $value['branch_reason'] ?? null;
        $needsReview = $value['needs_review'] ?? false;
        $confidence = $value['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw new InvalidArgumentException('Legacy screening result must contain numeric confidence.');
        }

        if (! is_bool($needsReview) || ($branchReason !== null && ! is_string($branchReason))) {
            throw new InvalidArgumentException('Legacy screening decision markers are invalid.');
        }

        if ($needsReview && $branchReason !== null) {
            throw new InvalidArgumentException('Legacy screening result cannot have both needs_review and branch_reason.');
        }

        if ($needsReview) {
            if ($confidence < 0.4 || $confidence > 0.6) {
                throw new InvalidArgumentException('Legacy needs_review result is outside the frozen borderline interval.');
            }

            return new ScreeningDecision(
                disposition: ScreeningDisposition::Review,
                reason: ScreeningDecisionReason::BorderlineConfidence,
                extractionDispatched: false,
            );
        }

        return match ($branchReason) {
            'ignore_rule' => new ScreeningDecision(
                disposition: ScreeningDisposition::Ignore,
                reason: ScreeningDecisionReason::IgnoreRule,
                extractionDispatched: false,
            ),
            'low_confidence' => self::lowConfidenceDecision($confidence),
            'unknown' => self::unknownTypeDecision($type, $confidence),
            'known_type_no_analyzer' => self::knownTypeDecision($type, $extractionDispatched, hasAnalyzer: false),
            'analyzer_available' => self::knownTypeDecision($type, $extractionDispatched, hasAnalyzer: true),
            default => throw new InvalidArgumentException('Legacy screening result has no deterministic decision mapping.'),
        };
    }

    private static function lowConfidenceDecision(float|int $confidence): ScreeningDecision
    {
        if ($confidence >= 0.4) {
            throw new InvalidArgumentException('Legacy low_confidence result is outside the frozen low-confidence interval.');
        }

        return new ScreeningDecision(
            disposition: ScreeningDisposition::Ignore,
            reason: ScreeningDecisionReason::LowConfidence,
            extractionDispatched: false,
        );
    }

    private static function unknownTypeDecision(AiDocumentType $type, float|int $confidence): ScreeningDecision
    {
        if ($type !== AiDocumentType::Unknown || $confidence <= 0.6) {
            throw new InvalidArgumentException('Legacy unknown result has no deterministic high-confidence unknown-type mapping.');
        }

        return new ScreeningDecision(
            disposition: ScreeningDisposition::Ignore,
            reason: ScreeningDecisionReason::UnknownType,
            extractionDispatched: false,
        );
    }

    private static function knownTypeDecision(AiDocumentType $type, bool $extractionDispatched, bool $hasAnalyzer): ScreeningDecision
    {
        if ($type === AiDocumentType::Unknown) {
            throw new InvalidArgumentException('Legacy known-type decision cannot target unknown.');
        }

        return new ScreeningDecision(
            disposition: $hasAnalyzer ? ScreeningDisposition::Extract : ScreeningDisposition::Retain,
            reason: $hasAnalyzer ? ScreeningDecisionReason::AnalyzerAvailable : ScreeningDecisionReason::KnownTypeNoAnalyzer,
            extractionDispatched: $extractionDispatched,
        );
    }

    private static function legacyCardinality(AiDocumentType $type): EstimateCardinality
    {
        if ($type === AiDocumentType::Estimate || $type === AiDocumentType::Unknown) {
            return EstimateCardinality::Unknown;
        }

        return EstimateCardinality::None;
    }

    private static function normalizePageCount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Stored screening page_count_estimate must be a non-negative integer or null.');
        }

        return $value;
    }
}
