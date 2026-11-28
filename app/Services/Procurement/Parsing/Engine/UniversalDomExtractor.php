<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Primitives\SelectorChain;
use App\Dto\Procurement\Parsing\Rules\CardDomRule;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;

/**
 * Универсальный DOM-экстрактор на базе fallback-цепочек CSS-селекторов.
 */
final class UniversalDomExtractor implements DomExtractor
{
    private CssSelectorConverter $converter;

    public function __construct()
    {
        $this->converter = new CssSelectorConverter(true);
    }

    public function loadHtml(string $html): DOMXPath
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($dom);
    }

    public function firstText(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): ?string
    {
        foreach ($chain->selectors as $selector) {
            $nodes = $this->querySelector($xpath, $selector->selector, $context);
            if ($nodes === []) {
                continue;
            }

            $text = $this->normalizeText($nodes[0]->textContent ?? '');
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function allTexts(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): array
    {
        foreach ($chain->selectors as $selector) {
            $nodes = $this->querySelector($xpath, $selector->selector, $context);
            if ($nodes === []) {
                continue;
            }

            $texts = [];
            foreach ($nodes as $node) {
                $text = $this->normalizeText($node->textContent ?? '');
                if ($text !== '') {
                    $texts[] = $text;
                }
            }

            if ($texts !== []) {
                return $texts;
            }
        }

        return [];
    }

    public function firstAttribute(DOMXPath $xpath, SelectorChain $chain, string $attribute, ?DOMNode $context = null): ?string
    {
        foreach ($chain->selectors as $selector) {
            $nodes = $this->querySelector($xpath, $selector->selector, $context);
            if ($nodes === []) {
                continue;
            }

            $node = $nodes[0];
            if ($node->attributes !== null) {
                $attrNode = $node->attributes->getNamedItem($attribute);
                if ($attrNode !== null) {
                    $value = trim((string) $attrNode->nodeValue);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return list<DOMNode>
     */
    public function queryAll(DOMXPath $xpath, SelectorChain $chain, ?DOMNode $context = null): array
    {
        foreach ($chain->selectors as $selector) {
            $nodes = $this->querySelector($xpath, $selector->selector, $context);
            if ($nodes !== []) {
                return $nodes;
            }
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    public function extractKeyValuePairs(DOMXPath $xpath, CardDomRule $domRule, ?DOMNode $context = null): array
    {
        if ($domRule->fieldRowsSelectors === null || $domRule->labelSelectors === null || $domRule->valueSelectors === null) {
            return [];
        }

        $rows = $this->queryAll($xpath, $domRule->fieldRowsSelectors, $context);
        $pairs = [];

        foreach ($rows as $row) {
            $label = $this->firstText($xpath, $domRule->labelSelectors, $row);
            $value = $this->firstText($xpath, $domRule->valueSelectors, $row);

            if ($label !== null && $value !== null) {
                $pairs[$label] = $value;
            }
        }

        return $pairs;
    }

    /**
     * @return list<DOMNode>
     */
    private function querySelector(DOMXPath $xpath, string $cssSelector, ?DOMNode $context): array
    {
        $prefix = $context !== null ? 'descendant-or-self::' : 'descendant-or-self::';
        $expr = $this->converter->toXPath($cssSelector, $prefix);

        if ($context !== null) {
            $expr = './/'.ltrim($expr, './');
        }

        $result = @$xpath->query($expr, $context);
        if (! $result instanceof DOMNodeList) {
            return [];
        }

        $nodes = [];
        foreach ($result as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function normalizeText(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));

        return is_string($collapsed) ? $collapsed : trim($text);
    }
}
