<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Procurement\Crawler\RawArtifact;
use App\Models\Procurement\Crawler\SourceIncident;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('crawler:prune-history {--limit=500 : Maximum rows per table in this invocation}')]
final class PruneCrawlerHistory extends Command
{
    public function handle(): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $cutoff = now()->subDays(90);
        $ids = SourceIncident::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit($limit)->pluck('id');
        $incidents = DB::table((new SourceIncident)->getTable())->whereIn('id', $ids)->delete();
        $artifacts = DB::transaction(function () use ($limit): int {
            $table = (new RawArtifact)->getTable();
            $rows = DB::table($table)->where('last_seen_at', '<', now()->subDays(30))->orderBy('last_seen_at')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();
            foreach ($rows as $row) {
                if (! is_string($row->disk) || ! is_string($row->path) || ! str_starts_with($row->path, 'raw/')) {
                    throw new RuntimeException('Invalid raw artifact catalog entry.');
                }
                if (! Storage::disk($row->disk)->delete($row->path)) {
                    throw new RuntimeException('Unable to delete expired raw artifact.');
                }
                DB::table($table)->where('id', $row->id)->delete();
            }

            return $rows->count();
        });
        $this->info("Pruned incidents: {$incidents}; raw artifacts: {$artifacts}.");

        return self::SUCCESS;
    }
}
