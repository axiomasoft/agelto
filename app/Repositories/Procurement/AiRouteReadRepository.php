<?php

declare(strict_types=1);

namespace App\Repositories\Procurement;

use App\Enums\Procurement\LlmPurpose;
use App\Models\Procurement\Ai\AiRoute;
use Illuminate\Database\Eloquent\Collection;

/**
 * Чтение матрицы роутинга `prc_ai_routes` (ТЗ 04 §3.1). Точное совпадение `document_type`
 * предпочитается зарезервированному `'any'` (пример матрицы: `(any, screening)` → общий
 * дешёвый маршрут, конкретный тип документа может переопределить его отдельной строкой).
 */
final readonly class AiRouteReadRepository
{
    /**
     * Активные маршруты для (document_type, purpose), отсортированы: точное совпадение
     * `document_type` раньше `'any'`, внутри группы — `priority` ASC (меньше = основной
     * маршрут, следующие по возрастанию — fallback/эскалация, ТЗ 04 §3.1).
     *
     * @return Collection<int, AiRoute>
     */
    public function activeCandidatesFor(string $documentType, LlmPurpose $purpose): Collection
    {
        return AiRoute::query()
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
}
