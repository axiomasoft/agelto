<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\RunStopKind;
use App\Models\Procurement\Crawler\Run;
use App\Services\Procurement\RunStopGate;
use Illuminate\Support\Carbon;

test('без stop_requested_at сигнала нет', function () {
    $run = Run::factory()->create(['stop_requested_at' => null, 'stop_kind' => null]);

    expect(app(RunStopGate::class)->signal($run))->toBeNull();
});

test('stop_requested_at + stop_kind=pause отдаёт RunStopKind::Pause', function () {
    $run = Run::factory()->create(['stop_requested_at' => Carbon::now(), 'stop_kind' => RunStopKind::Pause]);

    expect(app(RunStopGate::class)->signal($run))->toBe(RunStopKind::Pause);
});

test('stop_requested_at + stop_kind=cancel отдаёт RunStopKind::Cancel', function () {
    $run = Run::factory()->create(['stop_requested_at' => Carbon::now(), 'stop_kind' => RunStopKind::Cancel]);

    expect(app(RunStopGate::class)->signal($run))->toBe(RunStopKind::Cancel);
});

test('гейт читает свежее состояние строки, а не переданный в память объект', function () {
    $run = Run::factory()->create(['stop_requested_at' => null, 'stop_kind' => null]);

    Run::query()->whereKey($run->id)->update([
        'stop_requested_at' => Carbon::now(),
        'stop_kind' => RunStopKind::Cancel->value,
    ]);

    expect(app(RunStopGate::class)->signal($run))->toBe(RunStopKind::Cancel);
});
