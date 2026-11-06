<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Обёртка над `ocrmypdf` (Tesseract) и над зондами `pdftotext`/`pdfinfo` —
 * единственный вызывающий OCR-бинари в фабрике представлений; о `Document` и представлениях
 * не знает, домен живёт в {@see ScanStrategy} (тот же приём, что у {@see LibreOfficeConverter},
 * переиспользуется замером потолка `research/04-ocr-ceiling.md`).
 *
 * Таймаут OCR — СВОЙ (`documents.format_tools.ocr_seconds`), а не общий `default_timeout_seconds`:
 * распознавание сотни сканированных страниц идёт минутами, а не секундами.
 */
final readonly class OcrConverter
{
    /**
     * Сколько страниц PDF зондируется на текстовый слой — тот же канон, что в инвентаре корпуса
     * (`artifacts/corpus_inventory.py::inspect_pdf`): первые три страницы.
     */
    public const int TEXT_LAYER_SAMPLE_PAGES = 3;

    /**
     * OCR: скан-PDF → PDF с текстовым слоем + sidecar-текст. `--skip-text` оставляет страницы,
     * где текст уже есть, нетронутыми (документ может быть частично текстовым); `--output-type
     * pdf` — без принудительной PDF/A-нормализации Ghostscript'ом (она удваивает время и
     * переписывает страницы, а нам нужен текстовый слой, а не архивный формат).
     */
    public function run(string $inputPath, string $outputPdfPath, string $sidecarTxtPath, ?float $deadlineAt = null): void
    {
        $result = Process::timeout($this->admittedTimeout(
            operation: 'OCR',
            maximumSeconds: $this->timeoutSeconds(),
            remainingChainSeconds: $this->operationTimeoutSeconds('tesseract_version') + $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        ))
            ->run([
                $this->configString('ai.tools.ocrmypdf_bin'),
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
     * пробелы не считаются . `null` — зонд НЕ СМОГ классифицировать файл (битый
     * xref, вообще не PDF): это не «текста нет», а «неизвестно», и инвентарь корпуса в такой
     * ситуации оставлял `text_layer` пустым, а не писал `none`.
     */
    public function textCharsPerPage(string $pdfPath, ?float $deadlineAt = null): ?float
    {
        $result = Process::timeout($this->admittedTimeout(
            operation: 'pdftotext text-layer probe',
            maximumSeconds: $this->operationTimeoutSeconds('text_layer_probe'),
            remainingChainSeconds: $this->timeoutSeconds()
                + $this->operationTimeoutSeconds('tesseract_version')
                + $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        ))
            ->run([
                $this->configString('ai.tools.pdftotext_bin'),
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

    public function pageCount(string $pdfPath, ?float $deadlineAt = null): ?int
    {
        $result = Process::timeout($this->admittedTimeout(
            operation: 'pdfinfo page-count probe',
            maximumSeconds: $this->operationTimeoutSeconds('pdf_page_count'),
            remainingChainSeconds: 0,
            deadlineAt: $deadlineAt,
        ))
            ->run([$this->configString('ai.tools.pdfinfo_bin'), $pdfPath]);

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

    public function tesseractVersion(?float $deadlineAt = null): ?string
    {
        $result = Process::timeout($this->admittedTimeout(
            operation: 'Tesseract version probe',
            maximumSeconds: $this->operationTimeoutSeconds('tesseract_version'),
            remainingChainSeconds: $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        ))
            ->run([$this->configString('ai.tools.tesseract_bin'), '--version']);

        if ($result->failed()) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($result->output()), 2)[0]);

        return $firstLine !== '' ? $firstLine : null;
    }

    public function languages(): string
    {
        return $this->configString('ai.tools.ocr_languages', 'rus+eng');
    }

    private function timeoutSeconds(): int
    {
        return $this->configPositiveInt('documents.format_tools.ocr_seconds');
    }

    /**
     * Starts a tool only when its full configured timeout, every mandatory OCR operation after it,
     * and the persistence/release reserve still fit before the job deadline. This prevents a
     * successful probe from leaving an OCR process that Horizon must kill partway through.
     */
    private function admittedTimeout(string $operation, int $maximumSeconds, int $remainingChainSeconds, ?float $deadlineAt): int
    {
        if ($deadlineAt === null) {
            return $maximumSeconds;
        }

        $requiredSeconds = $maximumSeconds + $remainingChainSeconds + $this->persistenceReserveSeconds();
        $availableSeconds = (int) floor($deadlineAt - microtime(as_float: true));

        if ($availableSeconds < $requiredSeconds) {
            throw OperationBudgetExceeded::forOperation(
                operation: $operation,
                availableSeconds: $availableSeconds,
                requiredSeconds: $requiredSeconds,
            );
        }

        return $maximumSeconds;
    }

    private function operationTimeoutSeconds(string $operation): int
    {
        return $this->configPositiveInt("documents.format_tools.{$operation}_seconds");
    }

    private function persistenceReserveSeconds(): int
    {
        return $this->configPositiveInt('documents.format_tools.persistence_seconds');
    }

    private function configString(string $key, ?string $default = null): string
    {
        $value = config($key, $default);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("{$key} должен быть непустой строкой.");
        }

        return $value;
    }

    private function configPositiveInt(string $key, ?int $default = null): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new RuntimeException("{$key} должен быть положительным целым числом.");
        }

        return $value;
    }
}
