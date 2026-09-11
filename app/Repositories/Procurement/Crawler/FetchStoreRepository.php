<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Crawler;

use App\Models\Procurement\Crawler\Fetch;

final readonly class FetchStoreRepository
{
    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Fetch
    {
        return Fetch::query()->create($attributes);
    }
}
