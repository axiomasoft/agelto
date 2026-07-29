<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;

/**
 * ЕДИНСТВЕННОЕ определение фильтра «площадка участвует в показе» (§4.1 root/document-contour.md,
 * D91) — «обучающие сметы не на сайте» держится РОВНО этим условием. Применяется через именованный
 * `->publicPlatform()` на `Document`/`Estimate`/`Procurement` (`$relation` — путь до `Platform`:
 * прямой `platform` на `Document`/`Estimate`, вложенный `registry.platform` на `Procurement`, у
 * которой прямой колонки `platform_id` нет). Глобальный скоуп СОЗНАТЕЛЬНО отвергнут владельцем
 * (§4.3): забыть именованный скоуп можно, но забытое отключение глобального на произвольном job'е
 * пайплайна давало бы ТИХИЙ пустой результат вместо видимого пропуска фильтра.
 */
final class PublicPlatformScope
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $relation): Builder
    {
        return $query->whereHas($relation, fn (Builder $platform): Builder => $platform->where('is_public', true));
    }
}
