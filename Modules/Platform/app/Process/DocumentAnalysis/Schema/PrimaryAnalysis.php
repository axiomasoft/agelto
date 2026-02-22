<?php

declare(strict_types=1);

namespace Platform\Process\DocumentAnalysis\Schema;

use Spatie\LaravelData\Data;

/**
 * Данные первичного анализа (поле data в PrimaryAnalysisResponse).
 * - document_type: тип документа (DocumentType).
 * - description: краткое резюме файла — что это, за какой период, какой объём.
 * - tags: массив меток (Город, ОС, Шифр, Вид работ, ФИО).
 */
final class PrimaryAnalysis extends Data
{
    /**
     * @param  string[]  $tags
     */
    public function __construct(
        public DocumentType $document_type,
        public ?string $description = null,
        public array $tags = [],
    ) {}
}
