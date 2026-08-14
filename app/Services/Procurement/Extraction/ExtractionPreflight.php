<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Enums\Procurement\Document\UnprocessableReason;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Procurement\Extraction\Dto\PreflightDecision;
use App\Services\Procurement\Screening\PdfPageSlicer;
use RuntimeException;

/**
 * Preflight транспортных лимитов ДО первого upload (D29, `docs/specs/v2/08-estimate-extraction.md`
 * §4в): размер файла + число страниц (`qpdf --show-npages`, только для PDF — прочие форматы,
 * дошедшие до extraction, не листаются) против capability-матрицы активного маршрута
 * (`config('ai.capabilities.<model>')`). Не читается PDF на этапе preflight (крайне редкий
 * случай — документ уже прошёл скрининг, где qpdf его успешно прочитал) — исключение
 * пробрасывается наверх, оркестратор фиксирует его как обычный провал стадии (job-retry),
 * не unprocessable (нечитаемость на этом этапе — не транспортный лимит, а регрессия между
 * стадиями, требующая внимания, не констатации).
 */
final readonly class ExtractionPreflight
{
    public function __construct(
        private PdfPageSlicer $slicer,
    ) {}

    public function decide(string $localPath, string $extension, ResolvedRoute $route): PreflightDecision
    {
        // Индекс по модели БЕЗ dot-notation `config("ai.capabilities.{$model}")` — имя модели
        // само содержит точки («gemini-2.5-flash»), и Laravel развернул бы их как вложенные
        // сегменты пути (`gemini-2` → `5-flash`), теряя запись (тот же скрытый баг, что
        // `LlmCallRecorder::costMicros()`, P3.2 — там маскируется собственным fallback на 0,
        // здесь тихой деградацией preflight нельзя, поэтому индексируем массив напрямую).
        $capabilities = (array) config('ai.capabilities', []);
        $limits = $capabilities[$route->options->model] ?? null;

        if (! is_array($limits)) {
            throw new RuntimeException("Нет capability-записи для модели «{$route->options->model}» (config('ai.capabilities')).");
        }

        $sizeBytes = filesize($localPath);

        if ($sizeBytes === false) {
            throw new RuntimeException("Preflight: файл недоступен для чтения: {$localPath}");
        }

        $sizeMb = $sizeBytes / 1_000_000;
        $pages = $extension === 'pdf' ? $this->slicer->pageCount($localPath) : null;

        $maxRequestFileMb = $limits['max_request_file_mb'] ?? null;
        $maxPages = $limits['max_pages'] ?? null;

        if ((! is_float($maxRequestFileMb) && ! is_int($maxRequestFileMb)) || ! is_int($maxPages)) {
            throw new RuntimeException("Capability-запись модели «{$route->options->model}» содержит некорректные лимиты.");
        }

        $sizeOk = $sizeMb <= (float) $maxRequestFileMb;
        $pagesOk = $pages === null || $pages <= $maxPages;

        if ($sizeOk && $pagesOk) {
            return PreflightDecision::whole();
        }

        if ((bool) config('ai.extraction.windowed_enabled', false)) {
            return PreflightDecision::windowed();
        }

        return PreflightDecision::unprocessable(
            $sizeOk ? UnprocessableReason::ProviderLimitPages : UnprocessableReason::ProviderLimitSize,
        );
    }
}
