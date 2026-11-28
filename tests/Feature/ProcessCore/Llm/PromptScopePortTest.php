<?php

declare(strict_types=1);

use App\Enums\Llm\DocumentScope;
use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Services\Llm\Contracts\PromptScopeRegistry;
use App\Services\Llm\PromptCanonReader;

pest()->group('analysis-execution');

test('generic prompt port принимает registered scope и запрещает подмену consumer enum', function (): void {
    $registry = app(PromptScopeRegistry::class);
    expect($registry->documentType('any'))->toBe(DocumentTypeScope::Any)
        ->and($registry->documentType('estimate'))->toBe(AiDocumentType::Estimate)
        ->and(AiDocumentType::Estimate->key())->toBe('estimate');
    $foreign = new class implements DocumentScope
    {
        public function key(): string
        {
            return 'estimate';
        }
    };
    expect(fn () => $registry->documentType('not_registered'))->toThrow(RuntimeException::class)
        ->and(fn () => $registry->promptStepFor($foreign, 'extraction:rows'))->toThrow(InvalidArgumentException::class, 'Unregistered prompt document scope');
    expect(app(PromptCanonReader::class)->read())->not->toBeEmpty();
});
