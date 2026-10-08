<?php

declare(strict_types=1);

use App\Enums\Procurement\Proxy\ProxyScope;
use App\Models\Procurement\Crawler\Proxy;
use App\Services\Procurement\Proxy\ProxyResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('пароль прокси хранится в БД зашифрованным, а модель читает открытое значение', function (): void {
    $proxy = Proxy::factory()->create(['password' => 'p@ss:word']);

    $stored = DB::table('prc_proxies')->where('id', $proxy->id)->value('password');

    expect($stored)->not->toBe('p@ss:word')
        ->and(Crypt::decryptString($stored))->toBe('p@ss:word')
        ->and($proxy->fresh()->password)->toBe('p@ss:word');
});

test('пароль прокси не сериализуется моделью', function (): void {
    $proxy = Proxy::factory()->create(['password' => 'secret-value']);

    expect($proxy->fresh()->toArray())->not->toHaveKey('password')
        ->and($proxy->fresh()->toJson())->not->toContain('secret-value');
});

test('резолвер выдаёт лизу с расшифрованными учётными данными узла', function (): void {
    Proxy::factory()->country('RU')->withScopes([ProxyScope::CrawlListing])->create([
        'username' => 'user',
        'password' => 'p@ss:word',
    ]);

    $lease = app(ProxyResolver::class)->resolve(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    expect($lease->password)->toBe('p@ss:word')
        ->and($lease->httpOptions()['proxy'])->toContain('user:p%40ss%3Aword@');
});
