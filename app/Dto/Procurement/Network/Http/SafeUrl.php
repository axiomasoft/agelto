<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Network\Http;

use App\Dto\Procurement\Platform\Definition\CanonicalOrigin;
use App\Dto\Procurement\Process\Primitives\ContentHash;

/**
 * Абсолютный http(s) URL без userinfo/fragment. Полный URL (включая signed query) остаётся только
 * внутри объекта для transport; в логи/evidence идут template() без query и hash(). Host/DNS/
 * redirect policy каждого hop проверяет executor, не этот value type.
 */
final readonly class SafeUrl
{
    public CanonicalOrigin $origin;

    public function __construct(private string $value)
    {
        if (strlen($value) > 8192 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('URL must be bounded and free of whitespace or control bytes.');
        }
        $parts = parse_url($value);
        if (! is_array($parts) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('URL must be absolute and must not carry a fragment.');
        }
        $this->origin = CanonicalOrigin::fromUrl($value);
    }

    /** Только для transport binding; никогда не логировать. */
    public function reveal(): string
    {
        return $this->value;
    }

    /** Безопасный шаблон для логов: origin + path, без query/signature. */
    public function template(): string
    {
        $path = parse_url($this->value, PHP_URL_PATH);

        return $this->origin->value().(is_string($path) && $path !== '' ? $path : '/');
    }

    public function hash(): ContentHash
    {
        return ContentHash::of('url:v1:'.$this->value);
    }
}
