<?php

declare(strict_types=1);

namespace App\Services\Procurement\Parsing\Engine;

use App\Dto\Procurement\Parsing\Rules\DocumentsRule;
use App\Services\Procurement\Platform\Contracts\DocumentListExtractor;
use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use App\Services\Procurement\Platform\Contracts\DomExtractor;
use DOMXPath;

/**
 * Универсальный экстрактор списка документов из DOM карточки закупки.
 */
final class UniversalDocumentListExtractor implements DocumentListExtractor
{
    public function __construct(
        private readonly DomExtractor $dom,
        private readonly ?DocumentUrlPolicy $urlPolicy = null,
    ) {}

    /**
     * @return list<array{
     *     title: string|null,
     *     url: string|null,
     *     hash: string|null,
     *     size: string|null,
     *     published_at: string|null,
     * }>
     */
    public function extract(DOMXPath $xpath, DocumentsRule $rule): array
    {
        if ($rule->itemSelectors === null) {
            return [];
        }

        if ($rule->containerSelectors !== null) {
            $containers = $this->dom->queryAll($xpath, $rule->containerSelectors);
            if ($containers === []) {
                return [];
            }

            $items = [];
            foreach ($containers as $container) {
                foreach ($this->dom->queryAll($xpath, $rule->itemSelectors, $container) as $node) {
                    $items[] = $node;
                }
            }
        } else {
            $items = $this->dom->queryAll($xpath, $rule->itemSelectors);
        }
        $allowedExtensions = array_map(
            static fn (string $ext): string => ltrim(mb_strtolower(trim($ext)), '.'),
            $rule->allowedExtensions,
        );

        $results = [];

        foreach ($items as $itemNode) {
            $title = $rule->titleSelectors !== null ? $this->dom->firstText($xpath, $rule->titleSelectors, $itemNode) : null;
            $rawUrl = $rule->urlSelectors !== null
                ? $this->dom->firstAttribute($xpath, $rule->urlSelectors, 'href', $itemNode)
                : null;
            $url = $this->sanitizeDocumentUrl($rawUrl);
            if ($url === null) {
                continue;
            }

            if ($allowedExtensions !== [] && ! $this->matchesAllowedExtension($url, $title, $allowedExtensions)) {
                continue;
            }

            $hash = $rule->hashSelectors !== null ? $this->dom->firstText($xpath, $rule->hashSelectors, $itemNode) : null;
            $size = $rule->sizeSelectors !== null ? $this->dom->firstText($xpath, $rule->sizeSelectors, $itemNode) : null;
            $publishedAt = $rule->publishedAtSelectors !== null ? $this->dom->firstText($xpath, $rule->publishedAtSelectors, $itemNode) : null;

            $results[] = [
                'title' => $title,
                'url' => $url,
                'hash' => $hash,
                'size' => $size,
                'published_at' => $publishedAt,
            ];
        }

        return $results;
    }

    /**
     * @param  list<string>  $allowedExtensions
     */
    private function matchesAllowedExtension(?string $url, ?string $title, array $allowedExtensions): bool
    {
        foreach ([$url, $title] as $candidate) {
            if ($candidate === null) {
                continue;
            }
            $path = parse_url($candidate, PHP_URL_PATH);
            $source = is_string($path) && $path !== '' ? $path : $candidate;
            $ext = mb_strtolower(pathinfo($source, PATHINFO_EXTENSION));
            if ($ext !== '' && in_array($ext, $allowedExtensions, true)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeDocumentUrl(?string $rawUrl): ?string
    {
        if ($rawUrl === null) {
            return null;
        }

        $url = trim($rawUrl);
        if ($url === '' || str_starts_with($url, '//')) {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1 && ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        if (str_starts_with(strtolower($url), 'https://') && $this->urlPolicy !== null) {
            $this->urlPolicy->assertDocumentUrl($url);
        }

        return $url;
    }
}
