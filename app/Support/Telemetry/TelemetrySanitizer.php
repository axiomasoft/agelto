<?php

declare(strict_types=1);

namespace App\Support\Telemetry;

use App\Support\SecretRedactor;

/**
 * Единый санитайзер technical telemetry (observability#s05): логи, Sentry, breadcrumbs, envelope.
 * Не пытается распознать все секреты regex: известные носители (URL userinfo/query/fragment,
 * Authorization/Cookie, proxy credentials, URL-encoded пары, cursor, SQL) очищаются, а ключи
 * сырых payload (тело документа, prompt, ответ модели) не попадают в вывод вообще. Размер, глубина
 * и число полей ограничены. Без состояния; никогда не бросает исключение из-за формы входа.
 */
final readonly class TelemetrySanitizer
{
    public const string REDACTED = '[redacted]';

    public const string OMITTED = '[omitted]';

    private const int MAX_DEPTH = 5;

    private const int MAX_FIELDS = 50;

    private const int MAX_STRING = 500;

    private const string SECRET_KEY = '/password|passwd|secret|token|authorization|api.?key|cookie|credential|signature|bearer|proxy.?auth/i';

    /** Ключи, значение которых — сырой документ/prompt/ответ модели/cursor: не логируется даже в усечённом виде. */
    private const string RAW_KEY = '/^(?:body|html|raw|prompt|response|completion|content|payload|cursor|messages|candidates|sql|bindings|document_text|model_output|(?:.+_)?(?:raw|prompt|response|text|body|html|cursor))$/i';

    /**
     * Sentry HttpClientIntegration кладёт query/fragment отдельно от URL. Значение не обязано быть URL,
     * поэтому носитель подавляется целиком, независимо от имён параметров.
     */
    private const string HTTP_CARRIER = '/^http\.(?:query|fragment)$/i';

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function context(array $context): array
    {
        return $this->map($context, 0);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function stringContext(array $context): array
    {
        $safe = [];
        foreach ($this->context($context) as $key => $value) {
            if (is_string($key)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    public function text(string $text): string
    {
        $text = $this->withoutEmbeddedBody($text);
        // URL до остальных правил: userinfo/query/fragment отбрасываются целиком, а не маскируются по месту.
        $text = preg_replace_callback('~\b[a-z][a-z0-9+.\-]*://[^\s"\'<>]+~i', fn (array $match): string => $this->url($match[0]), $text) ?? self::REDACTED;
        $text = SecretRedactor::text($text);
        // URL-encoded пары key%3Dvalue / key%3Avalue вне URL.
        $text = preg_replace('~((?:password|passwd|secret|token|api[_-]?key|access_token|signature|sig|cursor)(?:%3D|%3A)\s*)[^\s&,;"\']+~i', '$1'.self::REDACTED, $text) ?? self::REDACTED;

        return mb_strlen($text) > self::MAX_STRING ? mb_substr($text, 0, self::MAX_STRING).'…[truncated]' : $text;
    }

    /** Origin + path без userinfo/query/fragment; query заменяется маркером наличия. */
    public function url(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'])) {
            return '[url]';
        }
        $path = isset($parts['path']) ? SecretRedactor::text($parts['path']) : '';
        $safe = $parts['scheme'].'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').$path;
        if (isset($parts['query'])) {
            $safe .= '?'.self::REDACTED;
        }

        return mb_strlen($safe) > self::MAX_STRING ? mb_substr($safe, 0, self::MAX_STRING).'…[truncated]' : $safe;
    }

    /**
     * Сообщение исключения для лога, Sentry exception value и legacy OperationLogWriter.
     * Тело HTTP-ответа, которое RequestException вшивает в getMessage(), не экспортируется:
     * остаётся только allowlisted диагностика (класс рядом, статус, код).
     */
    public function exceptionMessage(\Throwable|string $failure): string
    {
        return $this->text($failure instanceof \Throwable ? $failure->getMessage() : $failure);
    }

    /** @return array{class: class-string<\Throwable>, message: string, code: int|string, sqlstate?: string} */
    public function exception(\Throwable $failure): array
    {
        $safe = ['class' => $failure::class, 'message' => $this->exceptionMessage($failure), 'code' => $failure->getCode()];
        if ($failure instanceof \PDOException) {
            $state = $failure->errorInfo[0] ?? $failure->getCode();
            if (is_string($state) && strlen($state) === 5) {
                $safe['sqlstate'] = $state;
            }
        }

        return $safe;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function map(array $values, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['_truncated' => 'depth'];
        }
        $safe = [];
        $count = 0;
        foreach ($values as $key => $value) {
            if (++$count > self::MAX_FIELDS) {
                $safe['_truncated'] = count($values) - self::MAX_FIELDS;
                break;
            }
            $safe[$key] = match (true) {
                is_string($key) && preg_match(self::SECRET_KEY, $key) === 1 => self::REDACTED,
                is_string($key) && preg_match(self::HTTP_CARRIER, $key) === 1 => self::REDACTED,
                is_string($key) && preg_match(self::RAW_KEY, $key) === 1 => self::OMITTED,
                default => $this->value($value, $depth),
            };
        }

        return $safe;
    }

    private function value(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : null,
            is_string($value) => $this->text($value),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \UnitEnum => $value->name,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Throwable => $this->exception($value),
            $value instanceof \Stringable => $this->text((string) $value),
            is_array($value) => $this->map($value, $depth + 1),
            is_object($value) => '[object '.$value::class.']',
            default => '[unsupported]',
        };
    }

    /**
     * Laravel/Guzzle вшивают тело ответа в текст исключения. Оставляем статус и заголовок,
     * тело отбрасываем до остальных правил text(), чтобы URL в заголовке ещё очистился.
     */
    private function withoutEmbeddedBody(string $text): string
    {
        if (preg_match('/^(HTTP request returned status code \d+)/', $text, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/\bresulted in a `\d{3}\b/', $text) === 1) {
            $stripped = preg_replace('/response:\s.*/s', 'response: '.self::OMITTED, $text);

            return is_string($stripped) ? $stripped : self::OMITTED;
        }

        return $text;
    }
}
