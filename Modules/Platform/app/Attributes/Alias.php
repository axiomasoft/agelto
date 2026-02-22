<?php

declare(strict_types=1);

namespace Platform\Attributes;

use Attribute;

/** Указывает возможные алиасы (напр. заголовки колонок таблицы или маркеры для enum). */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Alias
{
    /**
     * @param  string[]  $aliases
     */
    public function __construct(
        public array $aliases = [],
    ) {}
}
