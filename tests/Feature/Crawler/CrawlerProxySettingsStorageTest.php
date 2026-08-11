<?php

declare(strict_types=1);

use App\Services\Procurement\Proxy\CrawlerProxySettingsResolver;
use App\Settings\Procurement\CrawlerProxySettings;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

test('настройка crawler proxy хранит nullable ID в выделенном репозитории', function (): void {
    $settings = new CrawlerProxySettings;

    expect($settings->defaultProxyId)->toBeNull();

    $settings->defaultProxyId = 42;
    $settings->save();

    expect(DB::table('prc_settings')->where([
        'group' => CrawlerProxySettings::group(),
        'name' => 'defaultProxyId',
    ])->value('payload'))->toBe('42');
});

test('resolver обновляет cached settings после сохранения другим process', function (): void {
    $resolver = app(CrawlerProxySettingsResolver::class);

    expect($resolver->defaultProxyId())->toBeNull();

    $writer = new Process(
        command: [
            PHP_BINARY,
            'artisan',
            'tinker',
            '--execute=$settings = new \\App\\Settings\\Procurement\\CrawlerProxySettings(); $settings->defaultProxyId = 73; $settings->save();',
        ],
        cwd: base_path(),
        env: array_replace($_ENV, [
            'APP_ENV' => 'testing',
            'DB_DATABASE' => DB::connection()->getDatabaseName(),
        ]),
    );
    $writer->mustRun();

    expect($resolver->defaultProxyId())->toBe(73);
});
