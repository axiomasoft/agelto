<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;

/**
 * Именованный фильтр публичных площадок (`is_public=true`) для Document/Estimate/Procurement.
 * `$relation` задаёт путь к Platform: `platform` у Document/Estimate и `registry.platform`
 * у Procurement. Явный `publicPlatform()` оставляет служебные документы доступными jobs,
 * которым нужны и публичные, и обучающие данные.
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
