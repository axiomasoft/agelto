<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\RepresentationKind;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\RepresentationStoreRepository;

/**
 * Какой файл версии уходит в LLM (D25). Политика по расширению исходника читается из
 * `config('ai.representations')` (P6.3, D3/D52, ADR `adr-input-representation.md`) — правило
 * живёт как ДАННЫЕ, ветка выбора легальна только здесь.
 */
final readonly class RepresentationResolver
{
    public function __construct(
        private RepresentationStoreRepository $representations,
    ) {}

    /**
     * `$forceKind` — сейм принуждения представления (P6.1, D50): бенчмарк обязан подать в
     * пайплайн ИМЕННО измеряемый вариант, а не тот, что выбрала бы дефолтная политика. Приоритет
     * (P6.3, D52): `$forceKind` > политика > оригинал версии. При переданном kind отдаём РОВНО
     * его — нет такого представления, значит ячейка бенчмарка `not_applicable`, не оригинал.
     * Дефолт `null` сохраняет сегодняшнее поведение байт-в-байт (всегда non-null).
     *
     * @return array{storagePath: string, disk: string, extension: string, mime: string}|null
     */
    public function forLlm(DocumentVersion $version, ?RepresentationKind $forceKind = null): ?array
    {
        $documentsDisk = (string) config('crawler.documents_disk');

        if ($forceKind !== null) {
            $representation = $this->representations->find($version, $forceKind);

            if ($representation === null) {
                return null;
            }

            return [
                'storagePath' => $representation->storage_path,
                'disk' => $representation->disk ?? $documentsDisk,
                'extension' => $forceKind->extension(),
                'mime' => $representation->mime,
            ];
        }

        foreach ($this->policyFor($version) as $kind) {
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

        if ((bool) config('ai.representations.fallback_to_source') === false) {
            return null;
        }

        return [
            'storagePath' => $version->storage_path,
            'disk' => $documentsDisk,
            'extension' => (string) $version->extension,
            'mime' => (string) $version->mime,
        ];
    }

    /**
     * @return list<RepresentationKind>
     */
    private function policyFor(DocumentVersion $version): array
    {
        $policy = (array) config('ai.representations.policy');
        $extension = strtolower((string) $version->extension);

        $kinds = $policy[$extension] ?? $policy['default'] ?? [];

        return array_values(array_filter(array_map(
            static fn (string $kind): ?RepresentationKind => RepresentationKind::tryFrom($kind),
            $kinds,
        )));
    }
}
