<?php

declare(strict_types=1);

use App\Services\Crawler\Eis\Card\DocumentsParser;

function documentsFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/documents.html'));
}

test('parse разбирает документы вкладки documents с external_id из ссылки filestore', function () {
    $documents = app(DocumentsParser::class)->parse(documentsFixtureHtml());

    expect($documents)->toHaveCount(2);

    expect($documents[0]->title)->toBe('notice.pdf')
        ->and($documents[0]->kind)->toBe('Извещение о проведении электронного аукциона')
        ->and($documents[0]->externalId)->toBe('aaaa-1111')
        ->and($documents[0]->sourceUrl)->toBe(
            'https://zakupki.gov.ru/epz/order/filestore/public/1.0/download/priz/file.html?uid=aaaa-1111',
        );

    expect($documents[1]->title)->toBe('contract-draft.pdf')
        ->and($documents[1]->externalId)->toBe('bbbb-2222');
});

test('parse пропускает строку без файла-ссылки', function () {
    $html = preg_replace(
        '#<td><a href="/epz/order/filestore/public/1\.0/download/priz/file\.html\?uid=bbbb-2222">contract-draft\.pdf</a></td>#',
        '<td>—</td>',
        documentsFixtureHtml(),
    );

    $documents = app(DocumentsParser::class)->parse($html);

    expect($documents)->toHaveCount(1)
        ->and($documents[0]->externalId)->toBe('aaaa-1111');
});

test('parse возвращает пустой список при отсутствии таблицы документов', function () {
    $documents = app(DocumentsParser::class)->parse('<html><body>нет документов</body></html>');

    expect($documents)->toBe([]);
});
