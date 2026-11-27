<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\Provider;
use Illuminate\Database\Eloquent\Collection;

final readonly class LlmProviderReadRepository
{
    /** @return Collection<int, Provider> */
    public function active(): Collection
    {
        return Provider::query()->where('is_active', true)->orderBy('code')->get();
    }
}
