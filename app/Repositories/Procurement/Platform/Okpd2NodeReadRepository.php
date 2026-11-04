<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Platform;

use App\Models\Procurement\Platform\Okpd2Node;
use Illuminate\Support\Collection;

final readonly class Okpd2NodeReadRepository
{
    /**
     * Карта «код → external_id» только для запрошенных кодов; ненайденные коды в результате
     * отсутствуют — отказ на нерезолвящийся код формирует вызывающий.
     *
     * @param  array<int, string>  $codes
     * @return array<string, string>
     */
    public function externalIds(int $platformId, array $codes): array
    {
        /** @var Collection<int, Okpd2Node> $nodes */
        $nodes = Okpd2Node::query()
            ->where('platform_id', $platformId)
            ->whereIn('code', $codes)
            ->get();

        return $nodes
            ->mapWithKeys(static fn (Okpd2Node $node): array => [$node->code => $node->external_id])
            ->all();
    }

    /**
     * Все узлы площадки, упорядоченные по коду — источник опций формы.
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
