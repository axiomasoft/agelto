<?php

declare(strict_types=1);

namespace App\Services\Procurement\Tuning;

use App\Enums\Procurement\CorrectionReason;
use App\Enums\Procurement\PromptSuggestionKind;
use App\Enums\Procurement\PromptSuggestionStatus;
use App\Models\Procurement\Ai\Correction;
use App\Models\Procurement\Ai\PromptSuggestion;
use App\Repositories\Procurement\PromptSuggestionStoreRepository;
use App\Services\Llm\PromptCanonReader;
use RuntimeException;

/**
 * Применение УЖЕ одобренного предложения (D8 — апрув остаётся ЕДИНСТВЕННЫМ путём к файлу): для
 * `prompt_patch` пишет файл канона (оба корня D32, поиск — `PromptCanonReader`) с поднятой версией
 * (D59), для `correction_hint` создаёт `prc_ai_corrections(is_reusable=true)` существующим
 * носителем (D60). Ни одна ветка не применяет предложение без `status=approved` — D8 не про
 * удобство, а про то, что носитель качества всего разбора не меняет машина.
 */
final readonly class PromptPatchApplier
{
    public function __construct(
        private PromptSuggestionStoreRepository $suggestions,
        private PromptCanonReader $canon,
    ) {}

    public function apply(PromptSuggestion $suggestion): PromptSuggestion
    {
        if ($suggestion->status !== PromptSuggestionStatus::Approved) {
            throw new RuntimeException(
                "Предложение #{$suggestion->id} не в статусе approved (текущий: {$suggestion->status->value}) — применение отказано.",
            );
        }

        return match ($suggestion->kind) {
            PromptSuggestionKind::PromptPatch => $this->applyPatch($suggestion),
            PromptSuggestionKind::CorrectionHint => $this->applyHint($suggestion),
        };
    }

    private function applyPatch(PromptSuggestion $suggestion): PromptSuggestion
    {
        if ($suggestion->prompt_key === null || $suggestion->proposed_content === null) {
            throw new RuntimeException("Предложение #{$suggestion->id} типа prompt_patch без prompt_key/proposed_content — повреждённая запись.");
        }

        $path = $this->findFileByKey($suggestion->prompt_key);
        $newVersion = $this->currentVersion($path) + 1;

        $this->rewriteFile($path, $suggestion->proposed_content, $newVersion);

        return $this->suggestions->markApplied($suggestion, $newVersion);
    }

    private function applyHint(PromptSuggestion $suggestion): PromptSuggestion
    {
        if ($suggestion->hint === null) {
            throw new RuntimeException("Предложение #{$suggestion->id} типа correction_hint без hint — повреждённая запись.");
        }

        if ($suggestion->analysis_id === null) {
            throw new RuntimeException("Предложение #{$suggestion->id} без analysis_id — correction_hint требует привязки к анализу.");
        }

        if ($suggestion->decided_by === null) {
            throw new RuntimeException("Предложение #{$suggestion->id} без decided_by — correction_hint требует автора решения.");
        }

        Correction::query()->create([
            'analysis_id' => $suggestion->analysis_id,
            'document_type' => $suggestion->document_type,
            'reason' => CorrectionReason::Other,
            'content' => $suggestion->rationale,
            'hint' => $suggestion->hint,
            'is_reusable' => true,
            'created_by' => $suggestion->decided_by,
        ]);

        return $this->suggestions->markApplied($suggestion, null);
    }

    private function findFileByKey(string $promptKey): string
    {
        return $this->canon->findPathByKey($promptKey)
            ?? throw new RuntimeException("Файл промта с key={$promptKey} не найден в объединённом пространстве корней канона (D32).");
    }

    private function currentVersion(string $path): int
    {
        $front = $this->parseFront($path);

        return (int) $front['version'];
    }

    /**
     * Поднимает `version` во front-matter на 1 и заменяет тело файла, СОХРАНЯЯ остальные поля
     * front-matter (`document_type`/`key`/`step`/`priority`/`is_active`) — формат, который читает `PromptSeeder`
     * (Code Guidance P7.4: не изобретать формат файла промта).
     */
    private function rewriteFile(string $path, string $newContent, int $newVersion): void
    {
        $front = $this->parseFront($path);
        $front['version'] = (string) $newVersion;

        $frontLines = [];
        foreach (['document_type', 'key', 'step', 'version', 'priority', 'is_active'] as $field) {
            if (! array_key_exists($field, $front)) {
                throw new RuntimeException("Файл промта без обязательного поля front-matter «{$field}»: {$path}");
            }

            $frontLines[] = "{$field}: {$front[$field]}";
        }

        $rewritten = "---\n".implode("\n", $frontLines)."\n---\n\n".trim($newContent)."\n";

        file_put_contents($path, $rewritten);
    }

    /**
     * @return array<string, string>
     */
    private function parseFront(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false || preg_match('/\A---\n(?<front>.*?)\n---\n/s', $raw, $matches) !== 1) {
            throw new RuntimeException("Файл промта без front-matter (---...---): {$path}");
        }

        $front = [];
        foreach (preg_split('/\n/', trim($matches['front'])) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $front[trim($name)] = trim($value);
        }

        return $front;
    }
}
