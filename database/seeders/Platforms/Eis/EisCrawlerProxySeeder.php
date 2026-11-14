<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;

/** Секреты и endpoints настраивает оператор через Proxyma; seed больше не меняет архив. */
final class EisCrawlerProxySeeder extends Seeder
{
    public const string SERVICE = 'ЕИС — RU CSV audit';

    public function run(): void
    {
        $profiles = DB::table(PackageSchema::tables()['route_profiles'])->where('tenant_key', TenantKey::SYSTEM)
            ->whereIn('profile_key', ['eis.audit', 'default'])->whereNull('retired_at')->pluck('profile_key')->all();
        if (count($profiles) !== 2) {
            throw new \RuntimeException('Configure Proxyma profiles eis.audit and default in the admin panel before audit seeding.');
        }
    }
}
