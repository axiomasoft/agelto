<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Fetch;
use App\Models\Procurement\Crawler\RawArtifact;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('crawler:catalog-raw-history {--after-id=0} {--limit=500} {--disk= : Override the historical disk if its configuration changed}')]
final class CatalogRawHistory extends Command
{
    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $after = max(0, (int) $this->option('after-id'));
        $rows = Fetch::query()->where('id', '>', $after)->whereNotNull('artifact_ref')->orderBy('id')->limit($limit)->get();
        foreach ($rows as $fetch) {
            $path = $fetch->getAttribute('artifact_ref');
            $disk = $this->option('disk') ?? config($fetch->getAttribute('stage') === CrawlStage::Detail->value ? 'crawler.raw_html_disk' : 'crawler.raw_disk');
            if (! is_string($disk) || $disk === '' || ! is_string($path) || ! str_starts_with($path, 'raw/')) {
                throw new RuntimeException('Historical raw artifact location is invalid; specify the owning disk.');
            }
            $id = hash('sha256', $disk.':'.$path);
            // Preserve the newest observation, including repeated references inside the backfill itself.
            $table = (new RawArtifact)->getTable();
            DB::table($table)->upsert([[
                'id' => $id, 'disk' => $disk, 'path' => $path, 'last_seen_at' => $fetch->getAttribute('fetched_at'),
            ]], ['id'], ['last_seen_at' => DB::raw('GREATEST(prc_raw_artifacts.last_seen_at, EXCLUDED.last_seen_at)')]);
            $after = $fetch->id;
        }
        $this->info('Catalogued: '.$rows->count().'; next --after-id='.$after);

        return self::SUCCESS;
    }
}
