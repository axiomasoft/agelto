<?php

declare(strict_types=1);

namespace App\Services\Procurement\Screening;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Резка первых N страниц PDF через `qpdf` CLI (D28, `docs/specs/v2/08-estimate-extraction.md`
 * §4в) — НЕ `setasign/fpdi`: бесплатная версия не читает PDF ≥1.5 с compressed cross-reference/
 * object streams (массовый формат вывода Гранд-Сметы/принтеров ЕИС/MS Office), `qpdf` их
 * нативно поддерживает. Документ с числом страниц ≤ N возвращается КАК ЕСТЬ, без реза
 * (Implementation Rules P5.1) — вызывающий код отличает срез от оригинала по совпадению путей
 * и не удаляет оригинал в `finally`.
 */
final readonly class PdfPageSlicer
{
    /**
     * Первые `$pages` страниц `$inputPath` во временный файл; документ короче/равен `$pages` —
     * возвращает `$inputPath` без изменений. Бросает `RuntimeException` с сырым stderr `qpdf`
     * на нечитаемом/зашифрованном PDF — классификация unreadable_format/password_protected
     * (D32) — ответственность вызывающего кода (`ScreeningProcessor`), не этого класса.
     */
    public function slice(string $inputPath, int $pages): string
    {
        $totalPages = $this->pageCount($inputPath);

        if ($totalPages <= $pages) {
            return $inputPath;
        }

        $outputPath = sys_get_temp_dir().'/screening-slice-'.Str::uuid().'.pdf';

        $result = Process::run(['qpdf', $inputPath, '--pages', '.', "1-{$pages}", '--', $outputPath]);

        if ($result->failed()) {
            throw new RuntimeException("qpdf: не удалось вырезать первые {$pages} стр.: ".trim($result->errorOutput()));
        }

        return $outputPath;
    }

    /**
     * Число страниц документа (`qpdf --show-npages`) — preflight скрининга (нужно N страниц ли
     * резать) и общий preflight транспортных лимитов (D29, P6.1).
     */
    public function pageCount(string $path): int
    {
        $result = Process::run(['qpdf', '--show-npages', $path]);

        if ($result->failed()) {
            throw new RuntimeException('qpdf: не удалось прочитать документ: '.trim($result->errorOutput()));
        }

        return (int) trim($result->output());
    }
}
