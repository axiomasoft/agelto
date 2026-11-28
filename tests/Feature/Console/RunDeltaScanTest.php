<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\AcquisitionKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProcessCore\AcquisitionProfileFixtures as P;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\ListingFixtures;

test('repeated delta command preserves an active Process owner and its update partition', function (): void {
    ListingFixtures::bind();
    $profile = P::create(AcquisitionKind::Delta);
    $arguments = ['profile' => (string) $profile->id, '--kind' => 'delta', '--command-id' => (string) Str::uuid()];
    $this->artisan('process:run', $arguments)->assertSuccessful();
    $row = DB::table('prc_process_work')->sole();
    FinalizeFixtures::running(new WorkId(new ProcessUuid($row->id)));
    $before = P::workSnapshot($row->id);
    $this->artisan('process:run', $arguments)->assertSuccessful();
    expect(P::workSnapshot($row->id))->toBe($before)
        ->and(DB::table('prc_crawl_runs')->sole()->listing_kind)->toBe('delta')
        ->and(P::cursors($profile)[0]->dateField)->toBe('update')
        ->and(DB::table('prc_process_runs')->count())->toBe(1)
        ->and(DB::table('prc_process_attempts')->count())->toBe(1);
});
