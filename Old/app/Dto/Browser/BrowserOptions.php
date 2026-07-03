<?php

declare(strict_types=1);

namespace App\Dto\Browser;

use Throwable;

final readonly class BrowserOptions
{
    public function __construct(
        public int $timeout = 90000,
        public bool $debug = false,
        public int $retries = 1,
        // Время ожидания между попытками в секундах
        public int $retry_timeout = 3,
        public bool $rotate_proxy_on_fail = false,
        /** @var array{key?: string}|null */
        public ?array $debugConfig = null,
        /** @var callable(?\HeadlessChromium\Page, Throwable): void|null */
        public mixed $onError = null,
    ) {}

    public static function fromArray(array $options): self
    {
        return new self(
            timeout: $options['timeout'] ?? 90000,
            debug: ! empty($options['debug']),
            retries: $options['retries'] ?? 1,
            retry_timeout: $options['retry_timeout'] ?? 3,
            rotate_proxy_on_fail: $options['rotate_proxy_on_fail'] ?? false,
            debugConfig: isset($options['debug']) && is_array($options['debug']) ? $options['debug'] : null,
            onError: $options['onError'] ?? null,
        );
    }

    public function getDebugKey(): ?string
    {
        return $this->debugConfig['key'] ?? null;
    }
}
