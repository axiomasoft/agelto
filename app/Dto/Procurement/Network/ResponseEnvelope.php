<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network;

/**
 * Полученный HTTP ответ: transport-успех, а не оценка содержимого. 4xx/5xx — тоже envelope;
 * их классификацию выполняют executor (retry/attribution) и adapter ResponseValidator.
 * wireBytes — байты сети, decodedBytes — после transport decompression (разные уровни evidence).
 */
final readonly class ResponseEnvelope
{
    /** @var array<string, list<string>> */
    public array $headers;

    /** @param array<string, list<string>> $headers */
    public function __construct(
        public int $status,
        array $headers,
        public ResponseBody $body,
        public int $wireBytes,
        public int $decodedBytes,
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('HTTP status out of range.');
        }
        if ($wireBytes < 0 || $decodedBytes < 0) {
            throw new \InvalidArgumentException('Body sizes must not be negative.');
        }
        $normalized = [];
        foreach ($headers as $name => $values) {
            $lower = strtolower($name);
            if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $lower) !== 1) {
                throw new \InvalidArgumentException('Invalid response header name.');
            }
            $normalized[$lower] = [...($normalized[$lower] ?? []), ...$values];
        }
        ksort($normalized, SORT_STRING);
        $this->headers = $normalized;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
}
