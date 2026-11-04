<?php

declare(strict_types=1);

use App\Dto\Procurement\Document\StorageReservationRequest;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Document\StoragePhase;
use App\Repositories\Procurement\Document\ArtifactRepository;
use App\Repositories\Procurement\Document\StorageCapacityRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

pest()->group('document-storage-and-references');

test('storage capacity сохраняется до подтверждённого cleanup, включая uncertain write и cleaning', function (): void {
    $capacity = app(StorageCapacityRepository::class);
    $domain = new ProcessUuid((string) Str::uuid());
    $capacity->ensureDomain($domain, 'test:'.Str::uuid(), 100, 90);
    $request = new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $domain,
        new ProcessUuid((string) Str::uuid()), 1, 60, StoragePhase::Download, 'local');
    $second = new StorageReservationRequest(new ProcessUuid((string) Str::uuid()), $domain,
        new ProcessUuid((string) Str::uuid()), 1, 60, StoragePhase::Download, 'local');
    DB::transaction(function () use ($capacity, $domain, $request, $second): void {
        $capacity->lockDomains([$domain]);
        expect($capacity->reserveLocked($request))->toBeTrue()
            ->and($capacity->reserveLocked($request))->toBeTrue()
            ->and($capacity->reserveLocked($second))->toBeFalse();
    });
    $capacity->beginWrite($request);
    expect(app(ArtifactRepository::class)->beginCleanup($request))->toBeFalse();
    $capacity->finishWrite($request);
    expect(app(ArtifactRepository::class)->beginCleanup($request))->toBeTrue();
    DB::transaction(function () use ($capacity, $domain, $second): void {
        $capacity->lockDomains([$domain]);
        expect($capacity->reserveLocked($second))->toBeFalse();
    });
    expect(DB::table('prc_storage_reservations')->where('id', $request->id->value)->value('status'))->toBe('cleaning')
        ->and(DB::table('prc_storage_reservations')->where('id', $request->id->value)->value('cleanup_verified_at'))->toBeNull();
});
