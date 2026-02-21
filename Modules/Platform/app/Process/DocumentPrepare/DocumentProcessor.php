<?php

declare(strict_types=1);

namespace Platform\Process\DocumentPrepare;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentPrepare\Strategies\PreparationStrategy;
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

    /**
     * Создание из существующей модели (напр. LinkAttachment)
     */
    public function fromModel(Model $model, ?string $field = null, ?string $collection = null): DocumentAnalysis
    {
        if ($field && filter_var($model->{$field}, FILTER_VALIDATE_URL)) {
            $url = (string) $model->{$field};
            $existing = $this->findExistingByUrl(url: $url);
            if ($existing !== null) {
                return $existing;
            }
        } elseif ($collection) {
            $media = $model->getFirstMedia($collection);
            if ($media !== null) {
                $existing = $this->findExistingByMedia(media: $media);
                if ($existing !== null) {
                    return $existing;
                }
            }
        }

        $analysis = DocumentAnalysis::create([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
        ]);

        if ($field && filter_var($model->{$field}, FILTER_VALIDATE_URL)) {
            $analysis->addMediaFromUrl((string) $model->{$field})->toMediaCollection(DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);
        } elseif ($collection) {
            $media = $model->getFirstMedia($collection);
            if ($media !== null) {
                $media->copy(model: $analysis, collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);
            }
        }

        return $this->prepare(analysis: $analysis);
    }

    /**
     * Создание из прямого Media объекта
     */
    public function fromMedia(Media $media): DocumentAnalysis
    {
        $existing = $this->findExistingByMedia(media: $media);
        if ($existing !== null) {
            return $existing;
        }

        $analysis = DocumentAnalysis::create();
        $media->copy(model: $analysis, collectionName: DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);

        return $this->prepare(analysis: $analysis);
    }

    /**
     * Создание из URL
     */
    public function fromUrl(string $url): DocumentAnalysis
    {
        $existing = $this->findExistingByUrl(url: $url);
        if ($existing !== null) {
            return $existing;
        }

        $analysis = DocumentAnalysis::create();
        $analysis->addMediaFromUrl(url: $url)->toMediaCollection(DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE);

        return $this->prepare(analysis: $analysis);
    }

    public function make(mixed $data, mixed ...$args): DocumentAnalysis
    {
        if ($data instanceof Media) {
            return $this->fromMedia($data, ...$args);
        }
        if ($data instanceof Model) {
            return $this->fromModel($data, ...$args);
        }
        if (is_string($data) && filter_var($data, FILTER_VALIDATE_URL)) {
            return $this->fromUrl($data, ...$args);
        }

        throw new Exception('Некорректный тип данных для DocumentProcessor');
    }

    /**
     * Внутренний метод: запуск стратегий подготовки
     */
    public function prepare(DocumentAnalysis $analysis): DocumentAnalysis
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
                callback: fn($q) => $q
                    ->where('collection_name', DocumentAnalysis::COLLECTION_DOCUMENT_SOURCE)
                    ->where('file_name', $fileName)
                    ->where('size', $size),
            )
            ->first();
    }
}
