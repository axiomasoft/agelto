<?php

declare(strict_types=1);

namespace Platform\Services;

final class PromtService
{
    /**
     * Удаляет из исходного кода PHP открывающий тег, namespace, use и declare.
     */
    public function getCleanSchema(string $filePath): string
    {
        $content = file_get_contents($filePath);

        $patterns = [
            '/<\?php/i',
            '/namespace\s+[^;]+;/i',
            '/use\s+[^;]+;/i',
            '/declare\s*\([^)]+\);/i',
        ];

        $cleanContent = preg_replace($patterns, '', $content);

        return mb_trim($cleanContent);
    }
}
