<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Procurement\SourceControl\SourceFailureClassifier;
use Illuminate\Http\Client\ConnectionException;

test('HTTP-статус классифицируется по стороне сбоя', function (int $status, SourceFailureKind $expected): void {
    expect(app(SourceFailureClassifier::class)->classifyResponse(httpStatus: $status, records: 0, mapped: 0, rowFailures: 0))
        ->toBe($expected);
})->with([
    '407 — прокси' => [407, SourceFailureKind::ProxyUnavailable],
    '403 — блокировка' => [403, SourceFailureKind::SourceBlocked],
    '429 — лимит' => [429, SourceFailureKind::SourceBlocked],
    '503 — площадка лежит' => [503, SourceFailureKind::SourceUnavailable],
    '408 — таймаут' => [408, SourceFailureKind::SourceUnavailable],
    '404 — не найдено (по конфигу)' => [404, SourceFailureKind::NotFound],
    '410 — прочий' => [410, SourceFailureKind::UnexpectedStatus],
    '502 — 5xx по умолчанию' => [502, SourceFailureKind::SourceUnavailable],
]);

test('успешный ответ без единой распознанной записи — ответ не по формату', function (): void {
    expect(app(SourceFailureClassifier::class)->classifyResponse(httpStatus: 200, records: 12, mapped: 0, rowFailures: 12))
        ->toBe(SourceFailureKind::InvalidContent);
});

test('частичные ошибки строк — деградация блока, а не пропуск', function (): void {
    $kind = app(SourceFailureClassifier::class)->classifyResponse(httpStatus: 200, records: 10, mapped: 9, rowFailures: 1);

    expect($kind)->toBe(SourceFailureKind::RowErrors)
        ->and($kind?->skipsBlock())->toBeFalse();
});

test('успешный блок и пустой блок не являются отказом', function (int $records, int $mapped): void {
    expect(app(SourceFailureClassifier::class)->classifyResponse(httpStatus: 200, records: $records, mapped: $mapped, rowFailures: 0))->toBeNull();
})->with([[0, 0], [10, 10]]);

test('исключения транспорта по типу, без разбора текста: прокси, соединение, прочее', function (): void {
    $classifier = app(SourceFailureClassifier::class);

    expect($classifier->classifyListingException(new NoProxyAvailableException(ProxyScope::CrawlListing, 'zakupki.gov.ru', 'пул пуст')))
        ->toBe(SourceFailureKind::ProxyUnavailable)
        ->and($classifier->classifyListingException(new ConnectionException('cURL error 56: CONNECT tunnel failed, response 502')))
        ->toBe(SourceFailureKind::SourceUnavailable)
        ->and($classifier->classifyListingException(new RuntimeException('bug')))->toBeNull();
});

test('скачанный блок без обязательных колонок — изменилась структура', function (): void {
    expect(app(SourceFailureClassifier::class)->classifyResponse(httpStatus: 200, records: 0, mapped: 0, rowFailures: 0, missingColumns: ['Дата обновления']))
        ->toBe(SourceFailureKind::LayoutDrift);
});

test('пустое тело при 2xx — EmptyBody; пустая выдача с заголовком — норма', function (): void {
    $classifier = app(SourceFailureClassifier::class);

    expect($classifier->classifyResponse(httpStatus: 200, records: 0, mapped: 0, rowFailures: 0, emptyBody: true))->toBe(SourceFailureKind::EmptyBody)
        ->and($classifier->classifyResponse(httpStatus: 200, records: 0, mapped: 0, rowFailures: 0))->toBeNull();
});

test('карточка: сеть, прокси и чужая страница — недоступность; прочее — ошибка нашего кода', function (): void {
    $classifier = app(SourceFailureClassifier::class);

    expect($classifier->classifyCardException(new ConnectionException('timeout')))->toBe(SourceFailureKind::SourceUnavailable)
        ->and($classifier->classifyCardException(new NoProxyAvailableException(ProxyScope::CrawlDetail, 'zakupki.gov.ru', 'пул пуст')))->toBe(SourceFailureKind::ProxyUnavailable)
        ->and($classifier->classifyCardException(new RuntimeException('bug')))->toBe(SourceFailureKind::CodeError);
});
