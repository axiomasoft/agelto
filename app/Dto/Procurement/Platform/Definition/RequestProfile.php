<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Platform\Definition;

use App\Enums\Procurement\Network\ResponseKind;

/**
 * Endpoint request profile. total — timeout ОДНОЙ
 * physical attempt; он никогда не урезается под остаток slice и не умножается attempts.
 * `min_total` отсутствует намеренно. Text profile ограничивает и entity, и UTF-8 expansion;
 * binary profile не имеет text limit/charset и не проходит text decoder.
 */
final readonly class RequestProfile
{
    /** @var list<int> */
    public array $delaysMs;

    public const int MAX_ATTEMPTS = 5;

    public const int MAX_REDIRECTS = 3;

    /**
     * @param  array<array-key, mixed>  $delaysMs  верхние границы full-jitter задержек перед попытками 2..N
     */
    public function __construct(
        public string $code,
        public int $connectSeconds,
        public int $lowSpeedSeconds,
        public int $totalSeconds,
        public int $maxEntityBytes,
        public ?int $maxTextBytes,
        public int $redirects,
        public int $attempts,
        public int $distinctRoutes,
        array $delaysMs,
        public ResponseKind $responseKind,
        public ?string $charsetDefault,
        public int $version,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*$/D', $code) !== 1 || strlen($code) > 64) {
            throw new \InvalidArgumentException('Invalid request profile code.');
        }
        foreach (['connectSeconds' => $connectSeconds, 'lowSpeedSeconds' => $lowSpeedSeconds, 'totalSeconds' => $totalSeconds, 'maxEntityBytes' => $maxEntityBytes, 'version' => $version] as $name => $value) {
            if ($value < 1) {
                throw new \InvalidArgumentException('Request profile '.$code.': '.$name.' must be positive.');
            }
        }
        if ($connectSeconds > $totalSeconds || $lowSpeedSeconds > $totalSeconds) {
            throw new \InvalidArgumentException('Request profile '.$code.': connect/low-speed bounds exceed the attempt total.');
        }
        if ($redirects < 0 || $redirects > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException('Request profile '.$code.': redirects out of range.');
        }
        if ($attempts < 1 || $attempts > self::MAX_ATTEMPTS) {
            throw new \InvalidArgumentException('Request profile '.$code.': attempts must be within 1..'.self::MAX_ATTEMPTS.'.');
        }
        if ($distinctRoutes < 1 || $distinctRoutes > min(2, $attempts)) {
            throw new \InvalidArgumentException('Request profile '.$code.': distinct routes must be within 1..attempts.');
        }
        if (! array_is_list($delaysMs) || count($delaysMs) !== $attempts - 1) {
            throw new \InvalidArgumentException('Request profile '.$code.': one delay bound is required before each repeated attempt.');
        }
        foreach ($delaysMs as $delay) {
            if (! is_int($delay) || $delay < 0 || $delay > 60_000) {
                throw new \InvalidArgumentException('Request profile '.$code.': delay bounds must be 0..60000 ms.');
            }
        }
        $this->delaysMs = $delaysMs;
        if ($responseKind === ResponseKind::Text) {
            if ($maxTextBytes === null || $maxTextBytes < $maxEntityBytes) {
                throw new \InvalidArgumentException('Request profile '.$code.': text profile requires a UTF-8 limit not below the entity limit.');
            }
            if ($charsetDefault !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,39}$/D', $charsetDefault) !== 1) {
                throw new \InvalidArgumentException('Request profile '.$code.': invalid default charset.');
            }
        } elseif ($maxTextBytes !== null || $charsetDefault !== null) {
            throw new \InvalidArgumentException('Request profile '.$code.': binary profile has no text limit or charset.');
        }
    }

    /**
     * Стабильная JSON-граница endpoint policy: точный набор ключей, без коэрции типов.
     *
     * @return array{code: string, connect_seconds: int, low_speed_seconds: int, total_seconds: int, max_entity_bytes: int, max_text_bytes: int|null, redirects: int, attempts: int, distinct_routes: int, delays_ms: list<int>, response_kind: string, charset_default: string|null, version: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'connect_seconds' => $this->connectSeconds,
            'low_speed_seconds' => $this->lowSpeedSeconds,
            'total_seconds' => $this->totalSeconds,
            'max_entity_bytes' => $this->maxEntityBytes,
            'max_text_bytes' => $this->maxTextBytes,
            'redirects' => $this->redirects,
            'attempts' => $this->attempts,
            'distinct_routes' => $this->distinctRoutes,
            'delays_ms' => $this->delaysMs,
            'response_kind' => $this->responseKind->value,
            'charset_default' => $this->charsetDefault,
            'version' => $this->version,
        ];
    }

    /** @param array<array-key, mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = ['code', 'connect_seconds', 'low_speed_seconds', 'total_seconds', 'max_entity_bytes', 'max_text_bytes', 'redirects', 'attempts', 'distinct_routes', 'delays_ms', 'response_kind', 'charset_default', 'version'];
        if (count($data) !== count($keys) || array_diff($keys, array_keys($data)) !== []) {
            throw new \InvalidArgumentException('Request profile requires exactly the declared keys.');
        }
        $delays = $data['delays_ms'];
        if (! is_array($delays) || ! array_is_list($delays)) {
            throw new \InvalidArgumentException('Request profile delays must be a list.');
        }
        $integers = [];
        foreach ($delays as $delay) {
            $integers[] = self::integer($delay);
        }
        $responseKind = ResponseKind::tryFrom(is_string($data['response_kind']) ? $data['response_kind'] : '')
            ?? throw new \InvalidArgumentException('Unknown request profile response kind.');

        return new self(
            code: self::text($data['code']),
            connectSeconds: self::integer($data['connect_seconds']),
            lowSpeedSeconds: self::integer($data['low_speed_seconds']),
            totalSeconds: self::integer($data['total_seconds']),
            maxEntityBytes: self::integer($data['max_entity_bytes']),
            maxTextBytes: $data['max_text_bytes'] === null ? null : self::integer($data['max_text_bytes']),
            redirects: self::integer($data['redirects']),
            attempts: self::integer($data['attempts']),
            distinctRoutes: self::integer($data['distinct_routes']),
            delaysMs: $integers,
            responseKind: $responseKind,
            charsetDefault: $data['charset_default'] === null ? null : self::text($data['charset_default']),
            version: self::integer($data['version']),
        );
    }

    private static function integer(mixed $value): int
    {
        return is_int($value) ? $value : throw new \InvalidArgumentException('Request profile field must be an integer.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \InvalidArgumentException('Request profile field must be a string.');
    }
}
