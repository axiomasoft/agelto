<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Platform;

use App\Models\Procurement\Platform\Okpd2Node;
use Illuminate\Support\Collection;

final readonly class Okpd2NodeReadRepository
{
    /**
     * Карта «код → external_id» только для запрошенных кодов; ненайденные коды в результате
     * отсутствуют — отказ на нерезолвящийся код формирует вызывающий (D21).
     *
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    public function externalIds(int $platformId, array $codes): array
    {
        return Okpd2Node::query()
            ->where('platform_id', $platformId)
            ->whereIn('code', $codes)
            ->pluck('external_id', 'code')
            ->all();
    }

    /**
     * Все узлы площадки, упорядоченные по коду — источник опций формы (P2.4).
     *
     * @return Collection<int, Okpd2Node>
     */
    public function selectableNodes(int $platformId): Collection
    {
        return Okpd2Node::query()
            ->where('platform_id', $platformId)
            ->orderBy('code')
            ->get();
    }
}
