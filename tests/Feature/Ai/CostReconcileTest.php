<?php

declare(strict_types=1);

use App\Models\Llm\LlmCall;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Pest\Laravel\artisan;

// Смещения во времени НЕБОЛЬШИЕ (часы/сутки): `llm_calls` партиционирована RANGE(called_at),
// далёкая дата ушла бы в несуществующую партицию — тот же приём, что в `UsageSourceTest`.

function billingExportPath(string $contents): string
{
    $folder = sys_get_temp_dir().'/billing-reconcile-'.Str::uuid();
    File::ensureDirectoryExists($folder);
    $path = "{$folder}/export.csv";
    file_put_contents($path, $contents);

    return $path;
}

test('дельта НИЖЕ порога — превышение не объявлено', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    LlmCall::factory()->create(['cost_micros' => 10_000_000, 'called_at' => $today->addHours(2)]);

    $export = billingExportPath("date,cost\n{$today->toDateString()},10.20\n");

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => $export,
    ])
        ->expectsOutputToContain('в пределах порога')
        ->assertSuccessful();
});

test('дельта ВЫШЕ порога — объявлена с процентом и разбивкой по дням', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    LlmCall::factory()->create(['cost_micros' => 2_300_000, 'called_at' => $today->addHours(2)]);

    $export = billingExportPath("date,cost\n{$today->toDateString()},12.00\n");

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => $export,
    ])
        ->expectsOutputToContain('превышает порог')
        ->assertSuccessful();
});

test('дельта ВЫШЕ порога с --fail-on-breach даёт ненулевой код возврата', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    LlmCall::factory()->create(['cost_micros' => 2_300_000, 'called_at' => $today->addHours(2)]);

    $export = billingExportPath("date,cost\n{$today->toDateString()},12.00\n");

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => $export,
        '--fail-on-breach' => true,
    ])
        ->expectsOutputToContain('превышает порог')
        ->assertFailed();
});

test('пустая выгрузка — явное сообщение, а не «расхождение 0 %»', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => base_path('tests/Fixtures/Llm/billing-export-empty.csv'),
    ])
        ->expectsOutputToContain('не содержит строк за указанный период')
        ->doesntExpectOutputToContain('0.00%')
        ->assertSuccessful();
});

test('незнакомый заголовок — отказ формой §1 с перечислением колонок', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => base_path('tests/Fixtures/Llm/billing-export-unknown-header.csv'),
    ])
        ->expectsOutputToContain('Незнакомые колонки')
        ->assertFailed();
});

test('currency ≠ USD — отказ, курс не пересчитывается', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => base_path('tests/Fixtures/Llm/billing-export-non-usd.csv'),
    ])
        ->expectsOutputToContain('EUR')
        ->assertFailed();
});

test('порог следует за config, а не литералом — одна дельта, разные вердикты', function (): void {
    $today = CarbonImmutable::now()->startOfDay();

    LlmCall::factory()->create(['cost_micros' => 10_000_000, 'called_at' => $today->addHours(2)]);

    $export = billingExportPath("date,cost\n{$today->toDateString()},12.00\n");

    config(['ai.reconcile.threshold_percent' => 5.0]);
    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => $export,
    ])->expectsOutputToContain('превышает порог')->assertSuccessful();

    config(['ai.reconcile.threshold_percent' => 50.0]);
    artisan('ai:cost-reconcile', [
        '--from' => $today->toDateString(),
        '--to' => $today->addDay()->toDateString(),
        '--file' => $export,
    ])->expectsOutputToContain('в пределах порога')->assertSuccessful();
});

test('команда зарегистрирована и описывает вход', function (): void {
    artisan('ai:cost-reconcile', ['--help' => true])
        ->expectsOutputToContain('--from')
        ->expectsOutputToContain('--to')
        ->expectsOutputToContain('--file')
        ->assertSuccessful();
});
