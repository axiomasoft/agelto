<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\ProcessUuid;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Postgres\ConcurrentTransactions;

pest()->group('document-storage-and-references');

test('два процесса не резервируют одни свободные bytes около capacity ceiling', function (): void {
    $domain = (string) Str::uuid();
    app(StorageCapacityRepository::class)->ensureDomain(new ProcessUuid($domain), 'race:'.$domain, 100, 90);
    $worker = <<<'PHP'
        $capacity = app(\App\Repositories\Procurement\Document\StorageCapacityRepository::class);
        $domain = new \App\Dto\Procurement\Process\ProcessUuid('%s');
        $request = new \App\Dto\Procurement\Document\StorageReservationRequest(
            new \App\Dto\Procurement\Process\ProcessUuid((string) \Illuminate\Support\Str::uuid()), $domain,
            new \App\Dto\Procurement\Process\ProcessUuid((string) \Illuminate\Support\Str::uuid()), 1, 60,
            \App\Enums\Procurement\Document\StoragePhase::from('%s'), 'local');
        return ['reserved' => app(\App\Services\Procurement\Process\ProcessTransaction::class)->run(function () use ($capacity, $domain, $request): bool {
            $capacity->lockDomains([$domain]);
            return $capacity->reserveLocked($request);
        }), 'pid' => \Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid];
        PHP;
    try {
        $results = ConcurrentTransactions::concurrently(['a' => sprintf($worker, $domain, 'download'), 'b' => sprintf($worker, $domain, 'prepare')]);
        expect($results['a']['pid'])->not->toBe($results['b']['pid'])
            ->and((int) $results['a']['reserved'] + (int) $results['b']['reserved'])->toBe(1)
            ->and(DB::table('prc_storage_reservations')->where('domain_id', $domain)->sum('byte_upper_bound'))->toBe('60');
    } finally {
        DB::table('prc_storage_reservations')->where('domain_id', $domain)->delete();
        DB::table('prc_storage_domains')->where('id', $domain)->delete();
    }
});
