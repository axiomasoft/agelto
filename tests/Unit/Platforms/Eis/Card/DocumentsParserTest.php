<?php

declare(strict_types=1);

use App\Enums\Procurement\DetailSnapshotStatus;
use App\Services\Platforms\Eis\Card\DocumentsParser;
use Illuminate\Support\Facades\Log;

function documentsFixtureHtml(): string
{
    return file_get_contents(base_path('tests/Fixtures/Platforms/Eis/Card/documents.html'));
}

test('parse разбирает документы вкладки documents с external_id из ссылки filestore на реальной вёрстке', function () {
    $snapshot = app(DocumentsParser::class)->parse(documentsFixtureHtml());
    $documents = $snapshot->documents;

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Complete)
        ->and($documents)->toHaveCount(6);

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

    $snapshot = app(DocumentsParser::class)->parse($html);
    $documents = $snapshot->documents;

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete)
        ->and($documents)->toHaveCount(5)
        ->and(array_column($documents, 'externalId'))->not->toContain('074262DCAA9B432E9530FB242D4E619F');
});

test('parse помечает отсутствующий контейнер прикреплённых файлов как incomplete', function () {
    $snapshot = app(DocumentsParser::class)->parse('<html><body>нет документов</body></html>');

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete)
        ->and($snapshot->documents)->toBe([]);
});

test('parse помечает selector drift с нераспознанными filestore ссылками как incomplete', function () {
    Log::spy();

    $html = str_replace('blockFilesTabDocs', 'someRenamedBlock', documentsFixtureHtml());

    $snapshot = app(DocumentsParser::class)->parse($html);

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Incomplete)
        ->and($snapshot->documents)->toBe([]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'не содержит контейнер вложений') && $context['filestore_links'] === 6)
        ->once();
});

test('parse считает пустой подтверждённый контейнер complete snapshot', function () {
    $snapshot = app(DocumentsParser::class)->parse('<div class="blockFilesTabDocs"></div>');

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Complete)
        ->and($snapshot->documents)->toBe([]);
});

test('parse отклоняет абсолютную ссылку вне allowlist как failed snapshot', function (): void {
    $html = '<div class="blockFilesTabDocs"><div class="attachment"><span class="section__value">'.
        '<a href="https://127.0.0.1/filestore?uid=ssrf" title="test.pdf">test.pdf</a>'.
        '</span></div></div>';

    $snapshot = app(DocumentsParser::class)->parse($html);

    expect($snapshot->status)->toBe(DetailSnapshotStatus::Failed)
        ->and($snapshot->documents)->toBe([]);
});
