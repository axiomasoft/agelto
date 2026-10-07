<?php

declare(strict_types=1);

use App\Enums\Procurement\Crawler\ListingFailureKind;
use App\Enums\Procurement\Proxy\ProxyScope;
use App\Exceptions\Procurement\Proxy\NoProxyAvailableException;
use App\Services\Procurement\SourceControl\ListingFailureClassifier;
use Illuminate\Http\Client\ConnectionException;

test('HTTP-статус классифицируется по стороне сбоя', function (int $status, ListingFailureKind $expected): void {
    expect((new ListingFailureClassifier)->classifyResponse(httpStatus: $status, records: 0, mapped: 0, rowFailures: 0))
        ->toBe($expected);
})->with([
    '407 — прокси' => [407, ListingFailureKind::ProxyFailure],
    '403 — блокировка' => [403, ListingFailureKind::SourceBlocked],
    '429 — лимит' => [429, ListingFailureKind::SourceBlocked],
    '503 — площадка лежит' => [503, ListingFailureKind::SourceUnavailable],
    '408 — таймаут' => [408, ListingFailureKind::SourceUnavailable],
    '404 — неожиданный' => [404, ListingFailureKind::UnexpectedStatus],
]);

test('успешный ответ без единой распознанной записи — смена формата', function (): void {
    expect((new ListingFailureClassifier)->classifyResponse(httpStatus: 200, records: 12, mapped: 0, rowFailures: 12))
        ->toBe(ListingFailureKind::UnrecognizedFormat);
});

test('частичные ошибки строк — деградация блока, а не пропуск', function (): void {
    $kind = (new ListingFailureClassifier)->classifyResponse(httpStatus: 200, records: 10, mapped: 9, rowFailures: 1);

    expect($kind)->toBe(ListingFailureKind::RowErrors)
        ->and($kind?->skipsBlock())->toBeFalse()
        ->and($kind?->countsTowardCircuit())->toBeFalse();
});

test('успешный блок и пустой блок не являются отказом', function (int $records, int $mapped): void {
    expect((new ListingFailureClassifier)->classifyResponse(httpStatus: 200, records: $records, mapped: $mapped, rowFailures: 0))->toBeNull();
})->with([[0, 0], [10, 10]]);

test('исключения транспорта: пул прокси, туннель прокси, цель, прочее', function (): void {
    $classifier = new ListingFailureClassifier;

    expect($classifier->classifyException(new NoProxyAvailableException(ProxyScope::CrawlListing, 'zakupki.gov.ru', 'пул пуст')))
        ->toBe(ListingFailureKind::ProxyPoolExhausted)
        ->and($classifier->classifyException(new ConnectionException('cURL error 56: CONNECT tunnel failed, response 502')))
        ->toBe(ListingFailureKind::ProxyFailure)
        ->and($classifier->classifyException(new ConnectionException('cURL error 28: Operation timed out')))
        ->toBe(ListingFailureKind::SourceUnavailable)
        ->and($classifier->classifyException(new RuntimeException('bug')))->toBeNull();
});

test('preflight: HTML важнее отсутствующих колонок, смена схемы открывает брейкер сразу', function (): void {
    $classifier = new ListingFailureClassifier;

    expect($classifier->classifyResponse(httpStatus: 200, records: 1, mapped: 0, rowFailures: 0, missingColumns: ['Дата обновления'], htmlBody: true))
        ->toBe(ListingFailureKind::HtmlInsteadOfData)
        ->and($classifier->classifyResponse(httpStatus: 200, records: 1, mapped: 0, rowFailures: 0, missingColumns: ['Дата обновления']))
        ->toBe(ListingFailureKind::SchemaDrift)
        ->and(ListingFailureKind::SchemaDrift->opensCircuitImmediately())->toBeTrue()
        ->and($classifier->looksLikeHtml('  <!DOCTYPE html><html>'))->toBeTrue()
        ->and($classifier->looksLikeHtml("Реестровый номер;Дата\n1;2"))->toBeFalse();
});
