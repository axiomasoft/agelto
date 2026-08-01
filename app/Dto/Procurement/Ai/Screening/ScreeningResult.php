<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Screening;

use App\Attributes\Ai\Description;
use App\Enums\Procurement\AiDocumentType;
use Spatie\LaravelData\Data;

/**
 * Ответ LLM на шаге screening (первичный анализ, docs/specs/v2/03-document-pipeline.md §3.4;
 * docs/specs/v2/08-estimate-extraction.md §3.5). Модель получает только первые N страниц
 * документа (D6) — это дешёвая классификация, не извлечение данных.
 */
final class ScreeningResult extends Data
{
    /**
     * @param  string[]  $tags
     */
    public function __construct(
        #[Description('тип документа — estimate/tech_spec/contract_draft/protocol/unknown.')]
        public AiDocumentType $document_type,
        #[Description('уверенность модели в классификации, от 0 (совсем не уверена) до 1 (полностью уверена).')]
        public float $confidence,
        #[Description('краткое резюме файла — что это, за какой период, какой объём.')]
        public ?string $description = null,
        #[Description('метки документа (Город, ОС, Шифр, Вид работ, ФИО) — как в исходном v1-классификаторе.')]
        public array $tags = [],
        #[Description('оценочное число страниц документа, если видно из среза.')]
        public ?int $page_count_estimate = null,
        #[Description('если документ точно не подходит для дальнейшей обработки — короткая причина на русском (иначе null; нечитаемое или неясное — null, не придумывать причину).')]
        public ?string $ignore_reason = null,
    ) {}
}
