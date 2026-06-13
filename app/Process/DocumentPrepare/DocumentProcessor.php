<?php

declare(strict_types=1);

namespace App\Process\DocumentPrepare;

use App\Enums\AnalysisStatus;
use App\Models\DocumentAnalysis\DocumentAnalysis;
use App\Process\DocumentPrepare\Strategies\PreparationStrategy;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Downloaders\DefaultDownloader;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class DocumentProcessor
{
    private array $strategies = [];

    public function __construct()
    {
        // Загружаем стратегии из конфига config('platform.document_prepare.strategies')
        $strategyClasses = config('platform.document_prepare.strategies', []);
        foreach ($strategyClasses as $class) {
            $this->strategies[] = app($class);
        }
    }

    public function run(DocumentAnalysis $analysis)
    {
        $analysis->transitionStatus(status: AnalysisStatus::Preparing, message: 'Начало подготовки документа');

        $this->loadMedia($analysis);

        $this->preparation($analysis);

        $analysis->transitionStatus(status: AnalysisStatus::Prepared, message: 'Документ подготовлен');
    }

    public function loadMedia(DocumentAnalysis $analysis)
    {
        $model = $analysis->getDocumentAnalysable();

        // Первичный документ (исходный файл)
        // Цель: в зависимости от типа модели, копируем файл в коллекцию document-source
        $sourceMedia = $analysis->getFirstMedia(DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);
        if ($sourceMedia === null) {
            if ($model instanceof Media) {
                $sourceMedia = $model->copy(
                    model: $analysis,
                    collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE,
                    diskName: config('app.filesystems.private_disk_name')
                );
            } elseif ($model instanceof Model) {
                if (filter_var($model->source, FILTER_VALIDATE_URL)) {
                    $sourceMedia = $model
                        ->addMediaFromUrl(url: $model->source)
                        ->toMediaCollection(
                            collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE,
                            diskName: config('app.filesystems.private_disk_name')
                        );
                } elseif (is_string($model->source)) {
                    $sourceMedia = $model->copy(
                        model: $analysis,
                        collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE,
                        diskName: config('app.filesystems.private_disk_name')
                    );
                } else {
                    throw new Exception('Некорректный тип данных для DocumentProcessor');
                }
            }
        }

        return $sourceMedia;
    }

    /**
     * Внутренний метод: запуск стратегий подготовки
     */
    public function preparation(DocumentAnalysis $analysis): DocumentAnalysis
    {
        $media = $analysis->getFirstMedia(collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);

        if (! $media) {
            throw new Exception("Source file not found for Analysis ID: {$analysis->id}");
        }

        // Обновляем расширение в базе
        $analysis->update(['extension' => $media->extension]);

        // Прогоняем по стратегиям (Excel -> PDF, ZIP -> Extract)
        foreach ($this->strategies as $strategy) {
            if ($strategy instanceof PreparationStrategy && $strategy->canProcess($media)) {
                $strategy->process($analysis, $media);
            }
        }

        return $analysis;
    }

    /**
     * Поиск существующего DocumentAnalysis по URL: скачивает файл во временную папку,
     * сравнивает размер и имя с document-source. При полном совпадении возвращает запись.
     */
    private function findExistingByUrl(string $url): ?DocumentAnalysis
    {
        $downloader = config(key: 'media-library.media_downloader', default: DefaultDownloader::class);
        $tempFile = (new $downloader)->getTempFile(url: $url);

        try {
            $size = filesize($tempFile);
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $filename = urldecode($filename);
            if ($filename === '') {
                $filename = 'file';
            }
            if (! Str::contains($filename, '.')) {
                $mediaExtension = explode('/', mime_content_type($tempFile));
                $filename = "{$filename}.{$mediaExtension[1]}";
            }

            return $this->findExistingByFileSignature(fileName: $filename, size: $size);
        } finally {
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    /**
     * Поиск существующего DocumentAnalysis по Media: сравнивает file_name и size
     * с document-source существующих записей.
     */
    private function findExistingByMedia(Media $media): ?DocumentAnalysis
    {
        return $this->findExistingByFileSignature(
            fileName: $media->file_name,
            size: $media->size,
        );
    }

    /**
     * Поиск DocumentAnalysis с document-source, у которого совпадают имя и размер файла.
     */
    private function findExistingByFileSignature(string $fileName, int $size): ?DocumentAnalysis
    {
        return DocumentAnalysis::query()
            ->whereHas(
                relation: 'media',
                callback: fn ($q) => $q
                    ->where(column: 'collection_name', operator: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE)
                    ->where(column: 'file_name', operator: $fileName)
                    ->where(column: 'size', operator: $size),
            )
            ->first();
    }
}
