<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Enums\Llm\DocumentTypeScope;
use App\Enums\Llm\LlmPurpose;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Models\Llm\LlmRoute;
use Illuminate\Database\Eloquent\Collection;

/**
 * Чтение матрицы роутинга `llm_routes` (ТЗ 04 §3.1). Точное совпадение `document_type`
 * предпочитается зарезервированному кросс-документному носителю `DocumentTypeScope::Any`
 * (пример матрицы: `(DocumentTypeScope::Any, screening)` → общий дешёвый маршрут, конкретный
 * тип документа может переопределить его отдельной строкой).
 */
final readonly class LlmRouteReadRepository
{
    /**
     * Активные маршруты для (document_type, purpose), отсортированы: точное совпадение
     * `document_type` раньше `DocumentTypeScope::Any`, внутри группы — `priority` ASC (меньше =
     * основной маршрут, следующие по возрастанию — fallback/эскалация, ТЗ 04 §3.1).
     *
     * @return Collection<int, LlmRoute>
     */
    public function activeCandidatesFor(AiDocumentType|DocumentTypeScope $documentType, LlmPurpose $purpose): Collection
    {
        $value = $documentType->value;

        return LlmRoute::query()
            ->with('provider')
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->where(function ($query) use ($value): void {
                $query->where('document_type', $value)->orWhere('document_type', DocumentTypeScope::Any->value);
            })
            ->orderByRaw('CASE WHEN document_type = ? THEN 0 ELSE 1 END', [$value])
            ->orderBy('priority')
            ->get();
    }

    /**
     * Первый активный теневой маршрут (`shadow=true`, P9.4, ТЗ 04 §5) для (document_type,
     * purpose) — та же сортировка exact-match/`priority`, что `activeCandidatesFor()`; `shadow`
     * маршруты — отдельное подмножество (обычный основной маршрут `shadow=false` этим методом
     * никогда не возвращается).
     */
    public function activeShadowRouteFor(AiDocumentType|DocumentTypeScope $documentType, LlmPurpose $purpose): ?LlmRoute
    {
        $value = $documentType->value;

        return LlmRoute::query()
            ->with('provider')
            ->where('purpose', $purpose->value)
            ->where('is_active', true)
            ->where('shadow', true)
            ->where(function ($query) use ($value): void {
                $query->where('document_type', $value)->orWhere('document_type', DocumentTypeScope::Any->value);
            })
            ->orderByRaw('CASE WHEN document_type = ? THEN 0 ELSE 1 END', [$value])
            ->orderBy('priority')
            ->first();
    }
}
