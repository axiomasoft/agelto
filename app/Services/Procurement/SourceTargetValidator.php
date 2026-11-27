<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Services\Procurement\Platform\Contracts\DocumentUrlPolicy;
use InvalidArgumentException;
use UnexpectedValueException;

final readonly class SourceTargetValidator implements DocumentUrlPolicy
{
    public function assertDocumentUrl(string $url): void
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw new InvalidArgumentException('Document source URL is malformed.');
        }

        $schemeValue = $parts['scheme'] ?? null;
        $hostValue = $parts['host'] ?? null;

        if (! is_string($schemeValue) || ! is_string($hostValue)) {
            throw new InvalidArgumentException('Document source URL must use HTTPS and include a host.');
        }

        $scheme = strtolower($schemeValue);
        $host = strtolower(rtrim($hostValue, '.'));
        $port = $parts['port'] ?? null;

        if ($scheme !== 'https' || $host === '') {
            throw new InvalidArgumentException('Document source URL must use HTTPS and include a host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Document source URL must not contain user information.');
        }

        if ($port !== null && $port !== 443) {
            throw new InvalidArgumentException('Document source URL must use the default HTTPS port.');
        }

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Document source URL must not use an IP literal.');
        }

        if (! in_array($host, $this->allowedHosts(), true)) {
            throw new InvalidArgumentException("Document source host '{$host}' is not trusted.");
        }
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        $hosts = config('documents.allowed_hosts');

        if (! is_array($hosts) || $hosts === []) {
            throw new UnexpectedValueException('documents.allowed_hosts must be a non-empty array.');
        }

        $normalized = [];

        foreach ($hosts as $host) {
            if (! is_string($host) || $host === '') {
                throw new UnexpectedValueException('documents.allowed_hosts must contain non-empty strings.');
            }

            $normalized[] = strtolower(rtrim($host, '.'));
        }

        return array_values(array_unique($normalized));
    }
}
