<?php

declare(strict_types=1);

namespace Database\Seeders\Procurement;

use App\Models\Procurement\Platform\Okpd2Node;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Сидирует карту «код ОКПД2 → внутренний id НСИ» площадки `eis` из промоушен-датафайла
 * `database/data/eis-okpd2-nodes.json` (снятого P1.2, D15). Идемпотентен: `updateOrCreate` по
 * `(platform_id, code)`. `synced_at` НЕ трогает — он означает «подтверждено обходом площадки»,
 * а сид данные с площадки не сверяет (заполняет только команда пересборки, P2.5).
 */
final class Okpd2NodeSeeder extends Seeder
{
    private const string DATA_FILE = 'database/data/eis-okpd2-nodes.json';

    public function run(): void
    {
        $platform = Platform::query()->where('code', 'eis')->first();

        if (! $platform instanceof Platform) {
            throw new RuntimeException(
                'Okpd2NodeSeeder: площадка eis не найдена — прогоните EisPlatformSeeder первым.'
            );
        }

        $payload = json_decode(File::get(self::DATA_FILE), associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ($payload['nodes'] as $node) {
            Okpd2Node::query()->updateOrCreate(
                ['platform_id' => $platform->id, 'code' => $node['code']],
                [
                    'external_id' => $node['external_id'],
                    'parent_code' => $node['parent_code'],
                    'level' => $node['level'],
                    'name' => $node['name'],
                ],
            );
        }
    }
}
