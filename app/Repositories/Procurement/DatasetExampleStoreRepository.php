<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Models\Procurement\Ai\DatasetExample;

/**
 * Запись `prc_ai_dataset_examples` (`root/service-layer-map.md` §5/§6) — `estimate:dataset:import`,
 * `estimate:gold:import`. Ключ апсерта общий, payload — параметр.
 */
final readonly class DatasetExampleStoreRepository
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function storeOrUpdate(int $documentVersionId, string $task, int $version, array $attributes): DatasetExample
    {
        return DatasetExample::query()->updateOrCreate(
            ['document_version_id' => $documentVersionId, 'task' => $task, 'version' => $version],
            $attributes,
        );
    }
}
