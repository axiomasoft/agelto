<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;

/**
 * Изображения (смета сканом, ТЗ 03 §3.3) — без конвертации, идут в vision-ветку
 * скрининга/извлечения как есть; extension/mime версии уже достаточно downstream-стадиям,
 * чтобы выбрать vision-путь — отдельный флаг не заводим (YAGNI).
 */
final readonly class ImageStrategy implements PreparationStrategy
{
    private const array IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'webp'];

    public function canProcess(DocumentVersion $version): bool
    {
        return in_array($version->extension, self::IMAGE_EXTENSIONS, true);
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        // Пометка vision-ветки — по extension/mime версии на стадии скрининга/извлечения.
        // `true` — изображение уходит в vision как есть, следующая стратегия не нужна (D28).
        return true;
    }
}
