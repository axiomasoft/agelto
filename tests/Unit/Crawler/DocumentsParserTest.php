<?php

declare(strict_types=1);

use App\Services\Procurement\Eis\Card\DocumentsParser;
use Illuminate\Support\Facades\Log;

function documentsFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Crawler/Card/documents.html'));
}

test('parse разбирает документы вкладки documents с external_id из ссылки filestore на реальной вёрстке', function () {
    $documents = app(DocumentsParser::class)->parse(documentsFixtureHtml());

    expect($documents)->toHaveCount(6);

    foreach ($documents as $document) {
        expect($document->title)->not->toBe('')
            ->and($document->sourceUrl)->not->toBeNull()->not->toBe('')
            ->and($document->externalId)->not->toBeNull()->not->toBe('');
    }

    expect($documents[0]->title)->toBe('НМЦК размещение информационного сообщения на страницах на 2025 год.xls')
        ->and($documents[0]->kind)->toBeNull()
        ->and($documents[0]->externalId)->toBe('F160E393C87D4C65ACEDF22204D1A9C9')
        ->and($documents[0]->sourceUrl)->toBe(
            'https://zakupki.gov.ru/44fz/filestore/public/1.0/download/priz/file.html?uid=F160E393C87D4C65ACEDF22204D1A9C9',
        );

    expect($documents[5]->title)->toBe('Электронный документ, полученный из внешней системы.xml')
        ->and($documents[5]->externalId)->toBe('074262DCAA9B432E9530FB242D4E619F');
});

test('parse пропускает строку без файла-ссылки', function () {
    $html = preg_replace(
        '#<span class="section__value">\s*<a href="https://zakupki\.gov\.ru/44fz/filestore/public/1\.0/download/priz/file\.html\?uid=074262DCAA9B432E9530FB242D4E619F"[^>]*>.*?</a>\s*</span>#s',
        '<span class="section__value">—</span>',
        documentsFixtureHtml(),
    );

    $documents = app(DocumentsParser::class)->parse($html);

    expect($documents)->toHaveCount(5)
        ->and(array_column($documents, 'externalId'))->not->toContain('074262DCAA9B432E9530FB242D4E619F');
});

test('parse возвращает пустой список при отсутствии блока прикреплённых файлов', function () {
    $documents = app(DocumentsParser::class)->parse('<html><body>нет документов</body></html>');

    expect($documents)->toBe([]);
});

test('parse логирует тихий отказ, если на странице есть ссылки filestore, а документы не распознаны', function () {
    Log::spy();

    $html = str_replace('blockFilesTabDocs', 'someRenamedBlock', documentsFixtureHtml());

    $documents = app(DocumentsParser::class)->parse($html);

    expect($documents)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'есть ссылки filestore, но не распознано') && $context['filestore_links'] === 6)
        ->once();
});
