<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Parsing\Primitives;

use App\Services\Procurement\Parsing\Validation\SafeRegex;

/**
 * Безопасное регулярное выражение, проверенное через {@see SafeRegex::assertSafe()}.
 */
final readonly class RegexPattern
{
    public string $pattern;

    public function __construct(string $pattern, ?int $minCaptureGroups = null)
    {
        SafeRegex::assertSafe($pattern, $minCaptureGroups);
        $this->pattern = $pattern;
    }

    public static function fromString(string $pattern, ?int $minCaptureGroups = null): self
    {
        return new self($pattern, $minCaptureGroups);
    }

    public function toString(): string
    {
        return $this->pattern;
    }
}
