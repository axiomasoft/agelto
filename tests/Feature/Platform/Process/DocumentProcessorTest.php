<?php

declare(strict_types=1);

use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentPrepare\DocumentProcessor;
use Spatie\MediaLibrary\Downloaders\Downloader;

beforeEach(function () {
    $this->processor = app(DocumentProcessor::class);
});

test('fromUrl returns same DocumentAnalysis when called twice with same url', function () {
    $fakeDownloader = new class implements Downloader
    {
        public function getTempFile(string $url): string
        {
            $path = tempnam(sys_get_temp_dir(), 'media-library');
            file_put_contents($path, 'test');

            return $path;
        }
    };

    config(['media-library.media_downloader' => get_class($fakeDownloader)]);

    $url = 'https://example.com/same-document.pdf';

    $first = $this->processor->fromUrl($url);
    $second = $this->processor->fromUrl($url);

    expect($first->id)->toBe($second->id)
        ->and(DocumentAnalysis::count())->toBe(1);
});

test('fromMedia returns same DocumentAnalysis when called twice with same media', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'doc_').'.txt';
    file_put_contents($tempFile, 'Test document content');

    try {
        $source = DocumentAnalysis::create();
        $source->addMedia($tempFile)->usingFileName('test-doc.txt')->toMediaCollection('document-source');
        $media = $source->getFirstMedia('document-source');

        $first = $this->processor->fromMedia($media);
        $second = $this->processor->fromMedia($media);

        expect($first->id)->toBe($second->id)
            ->and(DocumentAnalysis::count())->toBe(2);
    } finally {
        @unlink($tempFile);
    }
});

test('fromModel with collection returns same DocumentAnalysis when called twice with same model', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'doc_').'.pdf';
    file_put_contents($tempFile, 'Test PDF content');

    try {
        $source = DocumentAnalysis::create();
        $source->addMedia($tempFile)->usingFileName('test.pdf')->toMediaCollection('document-source');

        $first = $this->processor->fromModel($source, collection: 'document-source');
        $second = $this->processor->fromModel($source, collection: 'document-source');

        expect($first->id)->toBe($second->id)
            ->and(DocumentAnalysis::count())->toBe(2);
    } finally {
        @unlink($tempFile);
    }
});
