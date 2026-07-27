<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Обёртка над `ocrmypdf` (Tesseract, D22) и над зондами `pdftotext`/`pdfinfo` (P3.7) —
 * единственный вызывающий OCR-бинари в фабрике представлений; о `Document` и представлениях
 * не знает, домен живёт в {@see ScanStrategy} (тот же приём, что у {@see LibreOfficeConverter},
 * переиспользуется замером потолка `research/04-ocr-ceiling.md`).
 *
 * Таймаут OCR — СВОЙ (`ai.tools.ocr_timeout_seconds`), а не общий `default_timeout_seconds`:
 * распознавание сотни сканированных страниц идёт минутами, а не секундами.
 */
final readonly class OcrConverter
{
    /**
     * Сколько страниц PDF зондируется на текстовый слой — тот же канон, что в инвентаре корпуса
     * (P1.1, `artifacts/corpus_inventory.py::inspect_pdf`): первые три страницы.
     */
    public const int TEXT_LAYER_SAMPLE_PAGES = 3;

    /**
     * OCR: скан-PDF → PDF с текстовым слоем + sidecar-текст. `--skip-text` оставляет страницы,
     * где текст уже есть, нетронутыми (документ может быть частично текстовым); `--output-type
     * pdf` — без принудительной PDF/A-нормализации Ghostscript'ом (она удваивает время и
     * переписывает страницы, а нам нужен текстовый слой, а не архивный формат).
     */
    public function run(string $inputPath, string $outputPdfPath, string $sidecarTxtPath): void
    {
        $result = Process::timeout($this->timeoutSeconds())
            ->run([
                (string) config('ai.tools.ocrmypdf_bin'),
                '--language', $this->languages(),
                '--sidecar', $sidecarTxtPath,
                '--output-type', 'pdf',
                '--skip-text',
                $inputPath,
                $outputPdfPath,
            ]);

        if ($result->failed() || ! file_exists($outputPdfPath)) {
            throw new RuntimeException('OCR провалился: '.$result->errorOutput());
        }
    }

    /**
     * Символов текстового слоя на страницу по первым {@see TEXT_LAYER_SAMPLE_PAGES} страницам —
     * пробелы не считаются (канон P1.1). `null` — зонд НЕ СМОГ классифицировать файл (битый
     * xref, вообще не PDF): это не «текста нет», а «неизвестно», и инвентарь корпуса в такой
     * ситуации оставлял `text_layer` пустым, а не писал `none`.
     */
    public function textCharsPerPage(string $pdfPath): ?float
    {
        $result = Process::timeout((int) config('ai.tools.default_timeout_seconds'))
            ->run([
                (string) config('ai.tools.pdftotext_bin'),
                '-f', '1',
                '-l', (string) self::TEXT_LAYER_SAMPLE_PAGES,
                $pdfPath,
                '-',
            ]);

        if ($result->failed()) {
            return null;
        }

        $chars = strlen((string) preg_replace('/\s+/u', '', $result->output()));

        return $chars / self::TEXT_LAYER_SAMPLE_PAGES;
    }

    public function pageCount(string $pdfPath): ?int
    {
        $result = Process::timeout((int) config('ai.tools.default_timeout_seconds'))
            ->run([(string) config('ai.tools.pdfinfo_bin'), $pdfPath]);

        if ($result->failed()) {
            return null;
        }

        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^Pages:\s*(\d+)/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    public function tesseractVersion(): ?string
    {
        $result = Process::timeout((int) config('ai.tools.default_timeout_seconds'))
            ->run([(string) config('ai.tools.tesseract_bin'), '--version']);

        if ($result->failed()) {
            return null;
        }

        $firstLine = trim(strtok(trim($result->output()), "\n") ?: '');

        return $firstLine !== '' ? $firstLine : null;
    }

    public function languages(): string
    {
        return (string) config('ai.tools.ocr_languages', 'rus+eng');
    }

    private function timeoutSeconds(): int
    {
        return (int) config('ai.tools.ocr_timeout_seconds', 1800);
    }
}
