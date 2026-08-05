<?php

declare(strict_types=1);

use App\Services\Procurement\SourceTlsTrust;
use Symfony\Component\Finder\Finder;

test('хост из карты возвращает verify с путём бандла', function (): void {
    config(['crawler.tls.bundles' => ['zakupki.gov.ru' => '/etc/ssl/certs/russian-trusted-ca.pem']]);

    $options = (new SourceTlsTrust)->optionsFor('zakupki.gov.ru');

    expect($options)->toBe(['verify' => '/etc/ssl/certs/russian-trusted-ca.pem']);
});

test('хост вне карты возвращает массив без verify', function (): void {
    config(['crawler.tls.bundles' => ['zakupki.gov.ru' => '/etc/ssl/certs/russian-trusted-ca.pem']]);

    $options = (new SourceTlsTrust)->optionsFor('example.com');

    expect($options)->toBe([]);
});

test('пустая карта — системный трест для любого хоста', function (): void {
    config(['crawler.tls.bundles' => []]);

    $options = (new SourceTlsTrust)->optionsFor('zakupki.gov.ru');

    expect($options)->toBe([]);
});

test('в app/ нет verify со значением false ни в одном файле', function (): void {
    $violations = [];

    foreach (Finder::create()->files()->in([base_path('app'), base_path('config')])->name('*.php') as $file) {
        if (preg_match('/[\'"]verify[\'"]\s*=>\s*false/', $file->getContents()) === 1) {
            $violations[] = $file->getRelativePathname();
        }
    }

    expect($violations)->toBe([]);
});
