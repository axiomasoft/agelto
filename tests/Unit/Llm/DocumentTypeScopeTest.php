<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\AiDocumentType;

/**
 * §7 root/document-type-axis.md — сентинел кросс-документного назначения живёт в том же
 * пространстве значений, что и типы документа: неконфликтность обязана быть машинной.
 */
test('сентинел кросс-документного назначения не совпадает ни с одним типом документа', function (): void {
    expect(AiDocumentType::values())->not->toContain(DocumentTypeScope::Any->value);
});
