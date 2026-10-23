<?php

declare(strict_types=1);

namespace Database\Seeders\Procurement;

use App\Enums\Procurement\Proxy\CrawlerProxyMode;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Crawler\Proxy;
use App\Models\Procurement\Crawler\Run;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A separate January profile; does not overwrite the seeded annual profile. */
final class EisJanuary2025AuditSeeder extends Seeder
{
    public const string NAME = 'ЕИС 44-ФЗ — CSV аудит январь 2025 (завершённые, ОКПД2 43)';

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

            if ($crawler->exists && Run::query()->where('crawler_id', $crawler->id)->where('status', 'running')->exists()) {
                throw new RuntimeException('Pause the audit run before reseeding its profile.');
            }

            $this->call(EisCrawlerProxySeeder::class);
            $proxy = Proxy::query()->where('service', EisCrawlerProxySeeder::SERVICE)->sole();

            $filter = clone $source->filter;
            $filter->publishedFrom = CarbonImmutable::parse('2025-01-01');
            $filter->publishedTo = CarbonImmutable::parse('2025-01-31');
            // The default RUB 1bn ceiling excluded one January procurement in the live audit.
            // Independent unbounded source totals must still confirm this wider bound.
            $filter->priceTo = 1_000_000_000_000;

            $crawler->fill([
                'execution_lane' => $source->execution_lane,
                'mode' => $source->mode,
                'stop_policy' => $source->stop_policy,
                'source_method' => $source->source_method,
                'fallback_method' => null,
                'filter' => $filter,
                'options' => json_decode($source->getRawOriginal('options'), associative: true, flags: JSON_THROW_ON_ERROR),
                'schedule' => null,
                'is_active' => true,
                'proxy_mode' => CrawlerProxyMode::Default,
                'proxy_country' => 'RU',
            ])->save();
            $crawler->proxies()->sync([$proxy->id]);
            $crawler->update(['proxy_mode' => CrawlerProxyMode::Profile]);
        });
    }
}
