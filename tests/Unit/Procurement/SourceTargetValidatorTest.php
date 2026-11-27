<?php

declare(strict_types=1);

use App\Services\Procurement\SourceTargetValidator;

test('trusted EIS document target принимается', function (): void {
    config(['documents.allowed_hosts' => ['zakupki.gov.ru']]);

    app(SourceTargetValidator::class)->assertDocumentUrl(
        url: 'https://zakupki.gov.ru/epz/order/notice/filestore?uid=ok',
    );

    expect(true)->toBeTrue();
});

test('unsafe document target отклоняется до транспорта', function (string $url): void {
    config(['documents.allowed_hosts' => ['zakupki.gov.ru']]);

    expect(fn () => app(SourceTargetValidator::class)->assertDocumentUrl(url: $url))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'http' => 'http://zakupki.gov.ru/filestore?uid=x',
    'userinfo' => 'https://user:pass@zakupki.gov.ru/filestore?uid=x',
    'non-default port' => 'https://zakupki.gov.ru:8443/filestore?uid=x',
    'loopback' => 'https://127.0.0.1/filestore?uid=x',
    'link-local' => 'https://169.254.169.254/latest/meta-data?uid=x',
    'private ipv4' => 'https://10.0.0.1/filestore?uid=x',
    'ipv6 loopback' => 'https://[::1]/filestore?uid=x',
    'arbitrary host' => 'https://example.test/filestore?uid=x',
    'lookalike suffix' => 'https://zakupki.gov.ru.example.test/filestore?uid=x',
]);
