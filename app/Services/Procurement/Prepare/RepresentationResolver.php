<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Storage\FileLocationResolver;

/**
 * Какой файл версии уходит в LLM (D25). Политика по расширению исходника читается из
 * `config('ai.representations')` (P6.3, D3/D52, ADR `adr-input-representation.md`) — правило
 * живёт как ДАННЫЕ, ветка выбора легальна только здесь. Источник диска — {@see FileLocationResolver}
 * (P6.3, D115): внешняя форма `array{storagePath, disk, extension, mime}` не меняется, диск внутри
 * больше не читается из `config(...)` напрямую.
 */
final readonly class RepresentationResolver
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private FileLocationResolver $resolver,
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
        if ($forceKind !== null) {
            $representation = $this->representations->find($version, $forceKind);

            if ($representation === null) {
                return null;
            }

            $location = $this->resolver->forRepresentation($representation);

            return [
                'storagePath' => $location->path,
                'disk' => $location->disk,
                'extension' => $forceKind->extension(),
                'mime' => $representation->mime,
            ];
        }

        foreach ($this->policyFor($version) as $kind) {
            $representation = $this->representations->find($version, $kind);

            if ($representation !== null) {
                $location = $this->resolver->forRepresentation($representation);

                return [
                    'storagePath' => $location->path,
                    'disk' => $location->disk,
                    'extension' => $kind->extension(),
                    'mime' => $representation->mime,
                ];
            }
        }

        if ((bool) config('ai.representations.fallback_to_source') === false) {
            return null;
        }

        $location = $this->resolver->forDocumentVersion($version);

        return [
            'storagePath' => $location->path,
            'disk' => $location->disk,
            'extension' => (string) $version->extension,
            'mime' => (string) $version->mime,
        ];
    }

    /**
     * @return list<RepresentationKind>
     */
    private function policyFor(DocumentVersion $version): array
    {
        $policy = config('ai.representations.policy');

        if (! is_array($policy)) {
            return [];
        }

        $extension = strtolower($version->extension);

        $kinds = $policy[$extension] ?? $policy['default'] ?? [];

        if (! is_array($kinds)) {
            return [];
        }

        $resolved = [];

        foreach ($kinds as $kind) {
            if (! is_string($kind)) {
                continue;
            }

            $representationKind = RepresentationKind::tryFrom($kind);

            if ($representationKind !== null) {
                $resolved[] = $representationKind;
            }
        }

        return $resolved;
    }
}
