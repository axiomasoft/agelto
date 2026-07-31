<?php

declare(strict_types=1);

use App\Models\Procurement\Crawler\Proxy;
use App\Services\Procurement\ProxyPool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('пропускает неактивные узлы', function (): void {
    Proxy::factory()->create(['is_active' => false, 'fails_count' => 0]);
    $expected = Proxy::factory()->create(['is_active' => true, 'fails_count' => 0]);

    $picked = (new ProxyPool)->pick();

    expect($picked?->id)->toBe($expected->id);
});

test('выбирает узел с наименьшим числом отказов', function (): void {
    Proxy::factory()->create(['is_active' => true, 'fails_count' => 3]);
    $best = Proxy::factory()->create(['is_active' => true, 'fails_count' => 0]);

    $picked = (new ProxyPool)->pick();

    expect($picked?->id)->toBe($best->id);
});

test('reportFailure инкрементит счётчик и дизейблит узел на пороге', function (): void {
    config(['crawler.proxy_max_fails' => 2]);
    $proxy = Proxy::factory()->create(['is_active' => true, 'fails_count' => 1]);

    (new ProxyPool)->reportFailure($proxy);

    expect($proxy->refresh())
        ->fails_count->toBe(2)
        ->is_active->toBeFalse();
});

test('reportSuccess сбрасывает серию отказов и не роняет активность', function (): void {
    $proxy = Proxy::factory()->create(['is_active' => true, 'fails_count' => 4]);

    (new ProxyPool)->reportSuccess($proxy);

    expect($proxy->refresh())
        ->fails_count->toBe(0)
        ->is_active->toBeTrue();
});

test('proxyOptions собирает URL с кредами когда username задан', function (): void {
    $proxy = Proxy::factory()->create(['username' => 'user', 'password' => 'secret', 'host' => 'proxy.example', 'port' => 8080]);

    $options = (new ProxyPool)->proxyOptions($proxy);

    expect($options)->toBe(['proxy' => 'http://user:secret@proxy.example:8080']);
});

test('proxyOptions собирает URL без кредов когда username не задан', function (): void {
    $proxy = Proxy::factory()->create(['username' => null, 'password' => null, 'host' => 'proxy.example', 'port' => 8080]);

    $options = (new ProxyPool)->proxyOptions($proxy);

    expect($options)->toBe(['proxy' => 'http://proxy.example:8080']);
});
