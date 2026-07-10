<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Pulse\Facades\Pulse;

test('Pulse-recorder пишет запись и штатный ingest агрегирует её в pulse_aggregates', function () {
    // phpunit.xml глушит запись (`PULSE_ENABLED=false`, пред-существующий дефолт скелета — не
    // от этого item'а) — `startRecording()` включает её точечно для этого теста, публичный API.
    Pulse::startRecording();

    Pulse::record(type: 'observability_smoke', key: 'p7-5-test', value: 1)->count();

    Pulse::ingest();

    expect(DB::table('pulse_entries')->where('type', 'observability_smoke')->exists())->toBeTrue()
        ->and(DB::table('pulse_aggregates')->where('type', 'observability_smoke')->exists())->toBeTrue();
});
