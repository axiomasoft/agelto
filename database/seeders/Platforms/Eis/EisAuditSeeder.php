<?php

declare(strict_types=1);

namespace Database\Seeders\Platforms\Eis;

use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Proxyma\ProfileLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Proxyma\Domain\Policy\TenantKey;
use Proxyma\Infrastructure\Database\PackageSchema;
use RuntimeException;

/** A separate audit profile; does not overwrite the seeded annual profile. */
final class EisAuditSeeder extends Seeder
{
    public const string NAME = 'ЕИС 44-ФЗ — CSV аудит (завершённые, ОКПД2 43)';

    public function run(): void
    {
        DB::transaction(function (): void {
            $source = Crawler::query()
                ->where('name', 'ЕИС 44-ФЗ — архив 2025 (завершённые)')
                ->sole();
            $crawler = Crawler::query()->firstOrNew([
                'registry_id' => $source->registry_id,
                'name' => self::NAME,
            ]);

            if ($crawler->exists && DB::table('prc_process_runs as r')->join('prc_process_work as w', 'w.origin_run_id', '=', 'r.id')->where('r.profile_id', $crawler->id)->whereIn('w.state', ['admitted', 'running'])->exists()) {
                throw new RuntimeException('Pause the audit run before reseeding its profile.');
            }

            $this->call(EisCrawlerProxySeeder::class);

            $filter = clone $source->filter;

            $crawler->fill([
                'listing_kind' => $source->listing_kind,
                'filter' => $filter,
                'schedule' => null,
                'is_active' => true,
                'route_profile_id' => null,
            ])->save();
            $profile = DB::table((new ProfileLink)->getTable().' as l')
                ->join(PackageSchema::tables()['route_profiles'].' as p', 'p.id', '=', 'l.profile_id')
                ->where('l.tenant_key', TenantKey::SYSTEM)->where('p.tenant_key', TenantKey::SYSTEM)
                ->where('p.profile_key', 'eis.audit')->whereNull('p.retired_at')->orderByDesc('l.source_version')->value('l.source_profile_id');
            if ($profile === null) {
                throw new RuntimeException('EIS audit route profile is not installed.');
            }
            $crawler->update(['route_profile_id' => $profile]);
        });
    }
}
