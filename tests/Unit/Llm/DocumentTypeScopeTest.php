<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;

/** Сентинел междокументного назначения не должен конфликтовать с типами документов. */
test('сентинел кросс-документного назначения не совпадает ни с одним типом документа', function (): void {
    expect(AiDocumentType::values())->not->toContain(DocumentTypeScope::Any->value);
});
