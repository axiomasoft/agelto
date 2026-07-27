<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\RepresentationKind;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\RepresentationStoreRepository;

/**
 * Какой файл версии уходит в LLM (D25). Дефолтная политика повторяет поведение до P3.1
 * байт-в-байт: есть представление `pdf` — отдаём его, иначе оригинал версии.
 */
final readonly class RepresentationResolver
{
    /**
     * Правило выбора представления по типу источника и модели — P6, D3. Здесь ТОЛЬКО
     * сегодняшнее поведение: ветки «если модель такая — берём text_grid» тут запрещены.
     */
    private const array DEFAULT_PRIORITY = [RepresentationKind::Pdf];

    public function __construct(
        private RepresentationStoreRepository $representations,
    ) {}

    /**
     * @return array{storagePath: string, disk: string, extension: string, mime: string}
     */
    public function forLlm(DocumentVersion $version): array
    {
        $documentsDisk = (string) config('crawler.documents_disk');

        foreach (self::DEFAULT_PRIORITY as $kind) {
            $representation = $this->representations->find($version, $kind);

            if ($representation !== null) {
                return [
                    'storagePath' => $representation->storage_path,
                    'disk' => $representation->disk ?? $documentsDisk,
                    'extension' => $kind->extension(),
                    'mime' => $representation->mime,
                ];
            }
        }

        return [
            'storagePath' => $version->storage_path,
            'disk' => $documentsDisk,
            'extension' => (string) $version->extension,
            'mime' => (string) $version->mime,
        ];
    }
}
