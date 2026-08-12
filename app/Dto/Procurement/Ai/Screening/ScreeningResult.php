<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\EstimateCardinality;
use App\Services\Llm\Contracts\StructurallyValidatable;
use Illuminate\Contracts\Support\Arrayable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Ответ LLM на шаге screening (первичный анализ, docs/specs/v2/03-document-pipeline.md §3.4;
 * docs/specs/v2/08-estimate-extraction.md §3.5). Модель получает только первые N страниц
 * документа (D6) — это дешёвая классификация, не извлечение данных.
 */
final class ScreeningResult extends Data implements StructurallyValidatable
{
    /**
     * @param  string[]  $tags
     */
    public function __construct(
        #[Description('тип документа — estimate/tech_spec/contract_draft/protocol/unknown.')]
        public AiDocumentType $document_type,
        #[Description('уверенность модели в классификации, от 0 (совсем не уверена) до 1 (полностью уверена).')]
        public float $confidence,
        #[Description('краткое обязательное резюме видимого содержания документа; 1–1000 символов.')]
        public string $summary,
        #[Description('сколько логических смет видно в одном документе: none/single/multiple/unknown.')]
        public EstimateCardinality $estimate_cardinality,
        #[Description('нормализованные метки видимого содержания: 0–12 непустых значений, до 64 символов каждое.')]
        public array $tags,
        #[Description('необязательное пояснение классификации по видимому содержанию; не является backend-решением.')]
        public ?string $classification_note = null,
    ) {}

    public static function validateAndCreate(Arrayable|array $payload): static
    {
        $data = $payload instanceof Arrayable ? $payload->toArray() : $payload;

        if (array_key_exists('summary', $data)) {
            $data['summary'] = ScreeningValueNormalizer::requiredText($data['summary'], maximumLength: 1000, field: 'summary');
        }

        if (array_key_exists('tags', $data)) {
            $data['tags'] = ScreeningValueNormalizer::tags($data['tags']);
        }

        if (array_key_exists('classification_note', $data)) {
            $data['classification_note'] = ScreeningValueNormalizer::optionalText($data['classification_note'], maximumLength: 500, field: 'classification note');
        }

        return parent::validateAndCreate($data);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'document_type' => ['required'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'summary' => ['required', 'string', 'min:1', 'max:1000'],
            'estimate_cardinality' => ['required'],
            'tags' => ['present', 'array', 'max:12'],
            'tags.*' => ['required', 'string', 'min:1', 'max:64'],
            'classification_note' => ['nullable', 'string', 'min:1', 'max:500'],
        ];
    }

    public function structuralError(): ?string
    {
        if ($this->document_type === AiDocumentType::Estimate && $this->estimate_cardinality === EstimateCardinality::None) {
            return 'Screening estimate must have single, multiple, or unknown estimate cardinality.';
        }

        if ($this->document_type !== AiDocumentType::Estimate && in_array($this->estimate_cardinality, [EstimateCardinality::Single, EstimateCardinality::Multiple], strict: true)) {
            return 'Screening single or multiple estimate cardinality requires document_type=estimate.';
        }

        if ($this->document_type !== AiDocumentType::Unknown && $this->document_type !== AiDocumentType::Estimate && $this->estimate_cardinality !== EstimateCardinality::None) {
            return 'Known non-estimate screening types must have estimate_cardinality=none.';
        }

        return null;
    }
}
