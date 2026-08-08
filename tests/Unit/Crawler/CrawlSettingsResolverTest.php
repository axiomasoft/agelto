<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\CrawlStage;
use App\Models\Procurement\Crawler\Crawler;
use App\Models\Procurement\Platform\Platform;
use App\Services\Procurement\CrawlSettingsResolver;

test('переопределение профиля листинга выигрывает у площадки и конфига', function (): void {
    $platform = new Platform;
    $platform->throttle = ['stages' => ['listing' => ['retries' => 4, 'timeoutSeconds' => 20]]];

    $crawler = new Crawler;
    $crawler->options = ['retries' => 2, 'retryDelayMs' => 250];

    $settings = app(CrawlSettingsResolver::class)->forStage(CrawlStage::Listing, $platform, $crawler);

    expect($settings->retries)->toBe(2)
        ->and($settings->retryDelayMs)->toBe(250)
        ->and($settings->timeoutSeconds)->toBe(20);
});

test('настройка площадки выигрывает при пустом профиле', function (): void {
    $platform = new Platform;
    $platform->throttle = ['stages' => ['listing' => ['retries' => 4, 'timeoutSeconds' => 20]]];

    $settings = app(CrawlSettingsResolver::class)->forStage(CrawlStage::Listing, $platform, new Crawler);

    expect($settings->retries)->toBe(4)
        ->and($settings->timeoutSeconds)->toBe(20);
});

test('дефолт конфига применяется при пустых настройках профиля и площадки', function (): void {
    $settings = app(CrawlSettingsResolver::class)->forStage(CrawlStage::Listing, new Platform, new Crawler);

    expect($settings->retries)->toBe(5)
        ->and($settings->retryDelayMs)->toBe(500)
        ->and($settings->timeoutSeconds)->toBe(30);
});

test('явный ноль профиля не наследует паузу площадки', function (): void {
    $platform = new Platform;
    $platform->throttle = ['stages' => ['listing' => ['minPauseMs' => 1000]]];

    $crawler = new Crawler;
    $crawler->options = ['minPauseMs' => 0];

    $settings = app(CrawlSettingsResolver::class)->forStage(CrawlStage::Listing, $platform, $crawler);

    expect($settings->minPauseMs)->toBe(0);
});

test('лимит detail-пачки резолвится из настройки площадки или config-default', function (): void {
    $platform = new Platform;
    $platform->throttle = ['stages' => ['detail' => ['dispatchBatchLimit' => 25]]];

    $platformSettings = app(CrawlSettingsResolver::class)->forStage(CrawlStage::Detail, $platform);
    $defaultSettings = app(CrawlSettingsResolver::class)->defaultForStage(CrawlStage::Detail);

    expect($platformSettings->dispatchBatchLimit)->toBe(25)
        ->and($defaultSettings->dispatchBatchLimit)->toBe(100);
});
