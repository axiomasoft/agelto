<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use App\Exceptions\Procurement\Ai\PipelineDeadlineExceeded;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Резка первых N страниц PDF через `qpdf` CLI (`docs/specs/v2/08-estimate-extraction.md`
 * §4в) — НЕ `setasign/fpdi`: бесплатная версия не читает PDF ≥1.5 с compressed cross-reference/
 * object streams (массовый формат вывода Гранд-Сметы/принтеров ЕИС/MS Office), `qpdf` их
 * нативно поддерживает. Документ с числом страниц ≤ N возвращается КАК ЕСТЬ, без реза
 * — вызывающий код отличает срез от оригинала по совпадению путей
 * и не удаляет оригинал в `finally`.
 */
final readonly class PdfPageSlicer
{
    /**
     * Первые `$pages` страниц `$inputPath` во временный файл; документ короче/равен `$pages` —
     * возвращает `$inputPath` без изменений. Бросает `RuntimeException` с сырым stderr `qpdf`
     * на нечитаемом/зашифрованном PDF — классификация unreadable_format/password_protected
     * — ответственность вызывающего кода (`ScreeningProcessor`), не этого класса.
     */
    public function slice(string $inputPath, int $pages, ?float $deadlineAt = null): string
    {
        $totalPages = $this->pageCount(path: $inputPath, deadlineAt: $deadlineAt);

        if ($totalPages <= $pages) {
            return $inputPath;
        }

        $outputPath = PipelineTemporaryFile::create(prefix: 'screening_slice_', extension: 'pdf');

        $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run(['qpdf', $inputPath, '--pages', '.', "1-{$pages}", '--', $outputPath]);

        if ($result->failed()) {
            throw new RuntimeException("qpdf: не удалось вырезать первые {$pages} стр.: ".trim($result->errorOutput()));
        }

        return $outputPath;
    }

    /**
     * Число страниц документа (`qpdf --show-npages`) — preflight скрининга (нужно N страниц ли
     * резать) и общий preflight транспортных лимитов.
     */
    public function pageCount(string $path, ?float $deadlineAt = null): int
    {
        $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run(['qpdf', '--show-npages', $path]);

        if ($result->failed()) {
            throw new RuntimeException('qpdf: не удалось прочитать документ: '.trim($result->errorOutput()));
        }

        return (int) trim($result->output());
    }

    /**
     * Произвольный диапазон страниц `[$pageStart, $pageEnd]` (1-based, включительно) `$inputPath`
     * во ВСЕГДА НОВЫЙ временный файл (оконный путь §4.2 — в отличие от `slice`, здесь нет
     * короткого пути «документ короче диапазона» — вызывающий код, `WindowedChunkSource`, сам
     * отвечает за удаление среза сразу после использования, `finally`). Страницы среза
     * нумеруются заново с 1 — глобальную нумерацию исходного документа несёт вызывающий код
     * через offset-инструкцию в промте, не этот метод.
     */
    public function sliceRange(string $inputPath, int $pageStart, int $pageEnd, ?float $deadlineAt = null): string
    {
        $outputPath = PipelineTemporaryFile::create(prefix: 'extraction_window_', extension: 'pdf');

        $result = Process::timeout($this->timeout(deadlineAt: $deadlineAt))->run(['qpdf', $inputPath, '--pages', '.', "{$pageStart}-{$pageEnd}", '--', $outputPath]);

        if ($result->failed()) {
            throw new RuntimeException("qpdf: не удалось вырезать страницы {$pageStart}-{$pageEnd}: ".trim($result->errorOutput()));
        }

        return $outputPath;
    }

    private function timeout(?float $deadlineAt): int
    {
        $configured = config('documents.format_tools.pdf_slice_seconds', 60);

        if (! is_int($configured) || $configured < 1) {
            throw new RuntimeException('Screening PDF timeout must be a positive integer.');
        }

        if ($deadlineAt === null) {
            return $configured;
        }

        $remaining = (int) floor($deadlineAt - microtime(true));

        if ($remaining < 1) {
            throw new PipelineDeadlineExceeded('Screening PDF deadline is exhausted.');
        }

        return min($configured, $remaining);
    }
}
