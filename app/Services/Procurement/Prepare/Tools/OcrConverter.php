<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Exceptions\Procurement\Prepare\OperationBudgetExceeded;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Prepare\ToolUnavailable;
use App\Services\Procurement\Prepare\Strategies\ScanStrategy;
use App\Support\Config\TypedConfig;
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
    public function __construct(private BoundedToolProcess $tools) {}

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
        $timeout = $this->admittedTimeout(
            operation: 'OCR',
            maximumSeconds: $this->timeoutSeconds(),
            remainingChainSeconds: $this->operationTimeoutSeconds('tesseract_version') + $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        );
        $deadline = hrtime(true) / 1e9 + $timeout;

        try {
            $source = $this->tools->inputPath($inputPath);
            $this->tools->inWorkspace(prefix: 'ocr_tool_', operation: function (string $directory) use ($source, $outputPdfPath, $sidecarTxtPath, $deadline): void {
                $pdf = $directory.'/output.pdf';
                $sidecar = $directory.'/output.txt';
                $this->tools->run(
                    command: [
                        TypedConfig::nonEmptyString('ai.tools.ocrmypdf_bin'),
                        '--language', $this->languages(),
                        '--sidecar', $sidecar,
                        '--output-type', 'pdf',
                        '--skip-text',
                        $source,
                        $pdf,
                    ],
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                    inputPath: $source,
                    reportStderr: true,
                );
                if (file_exists($sidecar) || is_link($sidecar)) {
                    $this->tools->copyOutput(source: $sidecar, target: $sidecarTxtPath);
                }
                $this->tools->copyOutput(source: $pdf, target: $outputPdfPath);
            });
        } catch (ToolFailed|ToolUnavailable $exception) {
            throw new RuntimeException('OCR провалился: '.$exception->getMessage(), previous: $exception);
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
        $timeout = $this->admittedTimeout(
            operation: 'pdftotext text-layer probe',
            maximumSeconds: $this->operationTimeoutSeconds('text_layer_probe'),
            remainingChainSeconds: $this->timeoutSeconds()
                + $this->operationTimeoutSeconds('tesseract_version')
                + $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        );
        $output = $this->probe(
            command: [TypedConfig::nonEmptyString('ai.tools.pdftotext_bin'), '-f', '1', '-l', (string) self::TEXT_LAYER_SAMPLE_PAGES],
            timeout: $timeout,
            input: $pdfPath,
            argsAfterInput: ['-'],
        );

        if ($output === null) {
            return null;
        }

        $chars = strlen((string) preg_replace('/\s+/u', '', $output));

        return $chars / self::TEXT_LAYER_SAMPLE_PAGES;
    }

    public function pageCount(string $pdfPath, ?float $deadlineAt = null): ?int
    {
        $timeout = $this->admittedTimeout(
            operation: 'pdfinfo page-count probe',
            maximumSeconds: $this->operationTimeoutSeconds('pdf_page_count'),
            remainingChainSeconds: 0,
            deadlineAt: $deadlineAt,
        );
        $output = $this->probe(command: [TypedConfig::nonEmptyString('ai.tools.pdfinfo_bin')], timeout: $timeout, input: $pdfPath);

        if ($output === null) {
            return null;
        }

        foreach (explode("\n", $output) as $line) {
            if (preg_match('/^Pages:\s*(\d+)/', $line, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }

    public function tesseractVersion(?float $deadlineAt = null): ?string
    {
        $timeout = $this->admittedTimeout(
            operation: 'Tesseract version probe',
            maximumSeconds: $this->operationTimeoutSeconds('tesseract_version'),
            remainingChainSeconds: $this->operationTimeoutSeconds('pdf_page_count'),
            deadlineAt: $deadlineAt,
        );
        $output = $this->probe(command: [TypedConfig::nonEmptyString('ai.tools.tesseract_bin'), '--version'], timeout: $timeout);

        if ($output === null) {
            return null;
        }

        $firstLine = trim(explode("\n", trim($output), 2)[0]);

        return $firstLine !== '' ? $firstLine : null;
    }

    public function languages(): string
    {
        return TypedConfig::nonEmptyString('ai.tools.ocr_languages', 'rus+eng');
    }

    /**
     * @param  non-empty-list<string>  $command
     * @param  list<string>  $argsAfterInput
     */
    private function probe(array $command, int $timeout, ?string $input = null, array $argsAfterInput = []): ?string
    {
        $deadline = hrtime(true) / 1e9 + $timeout;
        try {
            $source = $input === null ? null : $this->tools->inputPath($input);
            if ($source !== null) {
                $command = [...$command, $source, ...$argsAfterInput];
            }

            return $this->tools->inWorkspace(
                prefix: 'ocr_probe_',
                operation: fn (string $directory): string => $this->tools->run(
                    command: $command,
                    directory: $directory,
                    execution: null,
                    deadline: $deadline,
                    inputPath: $source,
                ),
            );
        } catch (ToolFailed|ToolUnavailable) {
            return null;
        }
    }

    private function timeoutSeconds(): int
    {
        return TypedConfig::positiveInt('documents.format_tools.ocr_seconds');
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
        return TypedConfig::positiveInt("documents.format_tools.{$operation}_seconds");
    }

    private function persistenceReserveSeconds(): int
    {
        return TypedConfig::positiveInt('documents.format_tools.persistence_seconds');
    }
}
