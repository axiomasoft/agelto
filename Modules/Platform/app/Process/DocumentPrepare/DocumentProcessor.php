<?php

declare(strict_types=1);

namespace Platform\Process\DocumentPrepare;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Platform\Models\DocumentAnalysis\DocumentAnalysis;
use Platform\Process\DocumentPrepare\Strategies\PreparationStrategy;
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
        $analysis = DocumentAnalysis::create([
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
        ]);

        if ($field && filter_var($model->{$field}, FILTER_VALIDATE_URL)) {
            $analysis->addMediaFromUrl($model->{$field})->toMediaCollection('document-source');
        } elseif ($collection) {
            $media = $model->getFirstMedia($collection);
            if ($media) {
                $media->copy($analysis, 'document-source');
            }
        }

        return $this->prepare($analysis);
    }

    /**
     * Создание из прямого Media объекта
     */
    public function fromMedia(Media $media): DocumentAnalysis
    {
        $analysis = DocumentAnalysis::create();
        $media->copy($analysis, 'document-source');

        return $this->prepare($analysis);
    }

    /**
     * Создание из URL
     */
    public function fromUrl(string $url): DocumentAnalysis
    {
        $analysis = DocumentAnalysis::create();
        $analysis->addMediaFromUrl($url)->toMediaCollection('document-source');

        return $this->prepare($analysis);
    }

    /**
     * Внутренний метод: запуск стратегий подготовки
     */
    public function prepare(DocumentAnalysis $analysis): DocumentAnalysis
    {
        $media = $analysis->getFirstMedia('document-source');

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
}
