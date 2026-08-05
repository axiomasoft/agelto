<?php

declare(strict_types=1);

namespace App\Services\Procurement;

use App\Models\Procurement\Platform\Registry;
use RuntimeException;
use Spatie\LaravelData\Data;

/**
 * Резолвит класс типизированного фильтра профиля по коду реестра через карту
 * `config('crawler.filters')` (код реестра → FQCN `Data`-класса), не по `match`-лестнице и не по
 * `if ($registry->code === 'fz44')` в билдере (`config-driven-abstraction`; прецедент того же
 * домена — `SourceMethodFactory` + `config('crawler.source_methods')`).
 */
final readonly class CrawlerFilterFactory
{
    /**
     * @return class-string<Data>
     */
    public function for(Registry $registry): string
    {
        // `getAttribute()`, а не `->code`: докблок `@property string $code` у `Registry` не объявлен,
        // и объявить его здесь нельзя — это оставило бы без совпадения существующую запись
        // `phpstan-baseline.neon` (`EisCardUrlBuilder`, count: 2) и сделало бы анализ красным.
        $code = (string) $registry->getAttribute('code');
        $class = config("crawler.filters.{$code}");

        if (! is_string($class) || ! is_subclass_of($class, Data::class)) {
            throw new RuntimeException(
                "Для реестра «{$code}» не настроен класс фильтра: добавьте запись в config('crawler.filters')."
            );
        }

        return $class;
    }
}
