<?php

declare(strict_types=1);

namespace App\Services\Procurement\Process;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Retired raw namespaces only. Canonical acquisition evidence remains pinned. */
final readonly class RawArtifactRetention
{
    public function prune(int $limit): int
    {
        $rows = DB::table('prc_raw_artifacts')->whereRaw("last_seen_at < clock_timestamp() - interval '30 days'")
            ->where('path', 'not like', 'raw/acquisition/%')->orderBy('last_seen_at')->orderBy('id')->limit($limit)->get();
        $deleted = 0;
        foreach ($rows as $row) {
            if (! is_string($row->disk) || ! is_string($row->path) || ! str_starts_with($row->path, 'raw/')) {
                throw new \LogicException('Invalid retired raw artifact address.');
            }
            $deleted += self::withAddress($row->disk, $row->path, function () use ($row): int {
                // The producer shares this gate and refreshes last_seen_at before object I/O.
                $eligible = DB::table('prc_raw_artifacts')->where('id', $row->id)
                    ->where('last_seen_at', $row->last_seen_at)
                    ->whereRaw("last_seen_at < clock_timestamp() - interval '30 days'")->exists();
                if (! $eligible) {
                    return 0;
                }
                $disk = Storage::disk($row->disk);
                if (! $disk->delete($row->path) && $disk->exists($row->path)) {
                    throw new \RuntimeException('Retired raw artifact deletion failed.');
                }

                return DB::table('prc_raw_artifacts')->where('id', $row->id)->where('last_seen_at', $row->last_seen_at)->delete();
            });
        }

        return $deleted;
    }

    /** @template T
     * @param  \Closure(): T  $operation
     * @return T
     */
    public static function withAddress(string $disk, string $path, \Closure $operation): mixed
    {
        $key = $disk.':'.$path;
        // Session gate spans object I/O without opening a database transaction.
        DB::select('SELECT pg_advisory_lock(317, hashtext(?))', [$key]);
        try {
            return $operation();
        } finally {
            try {
                DB::select('SELECT pg_advisory_unlock(317, hashtext(?))', [$key]);
            } catch (\Throwable) {
            }
        }
    }
}
