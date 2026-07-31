<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate;

use App\Dto\Procurement\Analyzers\Estimate\RowsChunkResponse;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Enums\Procurement\RepresentationKind;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\RepresentationStoreRepository;
use App\Services\Llm\Dto\ComposedPrompt;
use App\Services\Llm\PromptComposer;
use Illuminate\Support\Facades\Storage;

/**
 * Единый строитель входа `estimate:dataset:export` (P7.1, D56) — ОДИН носитель для SFT и DPO:
 * вторая копия сборки `user`-турна разъехалась бы с первой при первой правке (`plan-protocol` §3).
 * `composePrompt()` вызывается ВЫЗЫВАЮЩИМ (`DatasetExporter`) РОВНО один раз на прогон — системная
 * часть детерминирована и не зависит от строки, `ComposedPrompt` пересчитывать на каждую запись
 * незачем (implicit-cache префикс, `PromptComposer` докблок). `resolveInput()` кладёт в `user`-
 * содержимое ФАКТИЧЕСКИЙ текст представления (`prc_document_representations`), а не ссылку на
 * документ.
 */
final readonly class DatasetInputBuilder
{
    public function __construct(
        private PromptComposer $prompts,
        private RepresentationStoreRepository $representations,
        private EstimateStepDictionaries $dictionaries,
    ) {}

    public function composePrompt(): ComposedPrompt
    {
        return $this->prompts->compose(
            EstimatePromptStep::ExtractionRows,
            [RowsChunkResponse::class, ...$this->dictionaries->forStep(EstimatePromptStep::ExtractionRows)],
        );
    }

    /**
     * @return array{representation_kind: RepresentationKind, text: string}|array{skip: string}
     */
    public function resolveInput(?DocumentVersion $version): array
    {
        if ($version === null) {
            return ['skip' => 'no_textual_representation'];
        }

        $resolved = $this->resolveTextual($version);

        if ($resolved === null) {
            return ['skip' => 'no_textual_representation'];
        }

        [$kind, $text] = $resolved;

        if (mb_strlen($text) > (int) config('ai.dataset_export.max_input_chars')) {
            return ['skip' => 'input_too_large'];
        }

        return ['representation_kind' => $kind, 'text' => $text];
    }

    public function userTurn(string $text, ?int $ordinal, ?string $estimateNumber): string
    {
        $label = $estimateNumber !== null ? " №{$estimateNumber}" : ($ordinal !== null ? " #{$ordinal}" : '');

        return "Извлеки все строки сметы{$label} из документа ниже.\n\n---\n\n{$text}";
    }

    /**
     * Первое существующее ТЕКСТОВОЕ (`mime` начинается на `text/`) представление версии по
     * приоритету `config('ai.dataset_export.representation_priority')`. `structured_xml` исключён
     * ЯВНО (в проде НЕ уходит в LLM целиком — обучение на нём учит неиспользуемому навыку),
     * остальные бинарные kind'ы отсекаются построением фильтра `text/`.
     *
     * @return array{0: RepresentationKind, 1: string}|null
     */
    private function resolveTextual(DocumentVersion $version): ?array
    {
        foreach ((array) config('ai.dataset_export.representation_priority') as $kindValue) {
            $kind = RepresentationKind::tryFrom((string) $kindValue);

            if ($kind === null || $kind === RepresentationKind::StructuredXml) {
                continue;
            }

            $representation = $this->representations->find($version, $kind);

            if ($representation === null || ! str_starts_with($representation->mime, 'text/')) {
                continue;
            }

            $disk = $representation->disk ?? (string) config('crawler.documents_disk');

            return [$kind, (string) Storage::disk($disk)->get($representation->storage_path)];
        }

        return null;
    }
}
