<?php

declare(strict_types=1);

namespace App\Services\Llm;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\Correction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Блок «Замечания оператора к предыдущему разбору» (P9.2, D15) — USER-часть повторного вызова,
 * НЕ системная (Implementation Rules: кэшируемый префикс `PromptComposer::compose()` неизменен,
 * ревью K п.22). Источники (объединяются, дубли по `id` схлопываются): (1) коррекции, привязанные
 * к анализу, который заменяет данный (`Analysis::parent_analysis_id` → `prc_ai_corrections.analysis_id`
 * старого прогона); (2) `is_reusable=true`-коррекции того же `document_type` из ЛЮБОГО анализа
 * (D15 — «применять ко ВСЕМ документам этого типа»). Текст строки — `hint ?? content`
 * (Implementation Rules): `hint` — переформулировка для промта, `content` — исходный текст
 * оператора, если переформулировки ещё нет.
 */
final readonly class CorrectionAwareComposer
{
    /**
     * `null` — корректировать нечего (первый прогон анализа, коррекций нет ни у родителя,
     * ни reusable для этого `document_type`) — вызывающий код НЕ добавляет пустой userPart.
     */
    public function correctionsBlock(Analysis $analysis): ?string
    {
        if ($analysis->parent_analysis_id === null && $analysis->document_type === null) {
            return null;
        }

        $corrections = Correction::query()
            ->where(function (Builder $query) use ($analysis): void {
                if ($analysis->parent_analysis_id !== null) {
                    $query->where('analysis_id', $analysis->parent_analysis_id);
                }

                if ($analysis->document_type !== null) {
                    $query->orWhere(function (Builder $query) use ($analysis): void {
                        $query->where('document_type', $analysis->document_type)
                            ->where('is_reusable', true);
                    });
                }
            })
            ->get()
            ->unique('id');

        if ($corrections->isEmpty()) {
            return null;
        }

        $lines = $corrections
            ->map(static fn (Correction $correction): string => '- '.($correction->hint ?? $correction->content))
            ->implode("\n");

        return "Замечания оператора к предыдущему разбору:\n{$lines}";
    }
}
