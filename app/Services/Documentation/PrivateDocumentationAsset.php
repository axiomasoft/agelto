<?php

declare(strict_types=1);

namespace App\Services\Documentation;

final readonly class PrivateDocumentationAsset
{
    /**
     * @param  array<string, string>  $securityHeaders
     */
    public function __construct(
        public string $path,
        public string $absolutePath,
        public string $mime,
        private array $securityHeaders,
        private string $htmlCsp,
    ) {}

    /**
     * @return array<string, string>
     */
    public function responseHeaders(): array
    {
        $headers = [
            'Content-Type' => $this->mime,
            ...$this->securityHeaders,
        ];

        if ($this->mime !== 'text/html; charset=utf-8') {
            return $headers;
        }

        return [
            ...$headers,
            'Content-Security-Policy' => $this->htmlCsp,
        ];
    }
}
