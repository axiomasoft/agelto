<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Enums\Procurement\LlmPurpose;
use App\Models\Llm\LlmRoute;
use Illuminate\Database\Eloquent\Collection;

/**
 * Чтение матрицы роутинга `llm_routes` (ТЗ 04 §3.1). Точное совпадение `document_type`
 * предпочитается зарезервированному `'any'` (пример матрицы: `(any, screening)` → общий
 * дешёвый маршрут, конкретный тип документа может переопределить его отдельной строкой).
 */
final readonly class LlmRouteReadRepository
{
    /**
     * Активные маршруты для (document_type, purpose), отсортированы: точное совпадение
     * `document_type` раньше `'any'`, внутри группы — `priority` ASC (меньше = основной
     * маршрут, следующие по возрастанию — fallback/эскалация, ТЗ 04 §3.1).
     *
     * @return Collection<int, LlmRoute>
     */
    public function activeCandidatesFor(string $documentType, LlmPurpose $purpose): Collection
    {
        return LlmRoute::query()
            ->with('provider')
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->where(function ($query) use ($documentType): void {
                $query->where('document_type', $documentType)->orWhere('document_type', 'any');
            })
            ->orderByRaw('CASE WHEN document_type = ? THEN 0 ELSE 1 END', [$documentType])
            ->orderBy('priority')
            ->get();
    }

    /**
     * Первый активный теневой маршрут (`shadow=true`, P9.4, ТЗ 04 §5) для (document_type,
     * purpose) — та же сортировка exact-match/`priority`, что `activeCandidatesFor()`; `shadow`
     * маршруты — отдельное подмножество (обычный основной маршрут `shadow=false` этим методом
     * никогда не возвращается).
     */
    public function activeShadowRouteFor(string $documentType, LlmPurpose $purpose): ?LlmRoute
    {
        return LlmRoute::query()
            ->with('provider')
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->where('shadow', true)
            ->where(function ($query) use ($documentType): void {
                $query->where('document_type', $documentType)->orWhere('document_type', 'any');
            })
            ->orderByRaw('CASE WHEN document_type = ? THEN 0 ELSE 1 END', [$documentType])
            ->orderBy('priority')
            ->first();
    }
}
