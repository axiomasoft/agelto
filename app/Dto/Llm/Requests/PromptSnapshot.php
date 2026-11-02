<?php

declare(strict_types=1);

namespace App\Dto\Llm\Requests;

final readonly class PromptSnapshot
{
    public string $hash;

    public function __construct(public string $content, public int $version)
    {
        if ($version < 1 || $content === '' || strlen($content) > 262144) {
            throw new \InvalidArgumentException('Invalid prompt snapshot.');
        }
        $this->hash = hash('sha256', $content);
    }
}
