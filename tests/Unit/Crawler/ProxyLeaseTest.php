<?php

declare(strict_types=1);

use App\Dto\Procurement\Proxy\ProxyLease;
use App\Enums\Procurement\Proxy\ProxyScope;

/**
 * Д-4: `httpOptions()` склеивал `"http://{$username}:{$password}@{$server}"` без кодирования
 * userinfo — пароль с символами вне unreserved-набора (`@`, `:`, `/`) ломал `proxy`-строку и
 * давал `cURL error 5: Unsupported proxy syntax` на каждом запросе.
 */
test('httpOptions percent-кодирует логин и пароль с спецсимволами userinfo (@ : /)', function () {
    $lease = ProxyLease::forProxy(
        proxyId: 1,
        host: '203.0.113.10',
        port: 8080,
        username: 'user@name',
        password: 'p@ss:w/rd',
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
    );

    expect($lease->httpOptions())->toBe([
        'proxy' => 'http://user%40name:p%40ss%3Aw%2Frd@203.0.113.10:8080',
    ]);
});

test('httpOptions использует rawurlencode, а не urlencode (пробел кодируется %20, не +)', function () {
    $lease = ProxyLease::forProxy(
        proxyId: 1,
        host: '203.0.113.10',
        port: 8080,
        username: 'user name',
        password: 'secret',
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
    );

    expect($lease->httpOptions()['proxy'])->toBe('http://user%20name:secret@203.0.113.10:8080');
});

test('httpOptions без кредов (username=null) не несёт userinfo', function () {
    $lease = ProxyLease::forProxy(
        proxyId: 1,
        host: '203.0.113.10',
        port: 8080,
        username: null,
        password: null,
        scope: ProxyScope::CrawlListing,
        target: 'zakupki.gov.ru',
    );

    expect($lease->httpOptions())->toBe(['proxy' => 'http://203.0.113.10:8080']);
});

test('direct-лиза даёт пустые httpOptions', function () {
    $lease = ProxyLease::direct(scope: ProxyScope::CrawlListing, target: 'zakupki.gov.ru');

    expect($lease->httpOptions())->toBe([]);
});
