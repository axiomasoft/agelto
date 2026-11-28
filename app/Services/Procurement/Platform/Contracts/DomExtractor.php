<?php

declare(strict_types=1);

namespace App\Services\Procurement\Platform\Contracts;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\CardDomRule;
use DOMNode;
use DOMXPath;

/**
 * Контракт извлечения данных из HTML DOM с использованием fallback-цепочек CSS-селекторов.
 */
interface DomExtractor
{
    public function loadHtml(string $html): DOMXPath;

    public function firstText(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): ?string;

    /**
     * @return list<string>
     */
    public function allTexts(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): array;

    public function firstAttribute(DOMXPath $xpath, SelectorChain $chain, string $attribute, ?DOMNode $context = null): ?string;

    /**
     * @return list<DOMNode>
     */
    public function queryAll(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): array;

    /**
     * @return array<string, string>
     */
    public function extractKeyValuePairs(DOMXPath $xpath, CardDomRule $domRule, ?DOMNode $context = null): array;
}
