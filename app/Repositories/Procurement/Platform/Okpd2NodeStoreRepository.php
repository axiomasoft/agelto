<?php

declare(strict_types=1);

namespace App\Repositories\Procurement\Platform;

use App\Models\Procurement\Platform\Okpd2Node;
use Illuminate\Support\Carbon;

/**
 * Единственная точка записи карты «код ОКПД2 → id НСИ» (`prc_okpd2_nodes`): пишет только то,
 * что снято обходом площадки. Сид (`Okpd2NodeSeeder`) сюда не ходит — он наливает
 * промоушен-датафайл и `synced_at` НЕ трогает, потому что «подтверждено обходом» — свойство
 * прогона команды, а не файла.
 */
final readonly class Okpd2NodeStoreRepository
{
    /**
     * Идемпотентная запись по `(platform_id, code)`, согласованная с unique index.
     * Отсутствующие в выгрузке узлы не удаляются: один обход не доказывает удаление на площадке.
     *
     * @param  array{code: string, external_id: string, parent_code: string|null, level: int, name: string}  $node
     */
    public function storeOrUpdate(int $platformId, array $node, Carbon $syncedAt): Okpd2Node
    {
        return Okpd2Node::query()->updateOrCreate(
            ['platform_id' => $platformId, 'code' => $node['code']],
            [
                'external_id' => $node['external_id'],
                'parent_code' => $node['parent_code'],
                'level' => $node['level'],
                'name' => $node['name'],
                'synced_at' => $syncedAt,
            ],
        );
    }
}
