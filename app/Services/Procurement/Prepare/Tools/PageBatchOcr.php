<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare\Tools;

use App\Dto\Procurement\Document\Prepare\PdfBatchText;
use App\Dto\Procurement\Document\Prepare\PreparePage;
use App\Dto\Procurement\Document\Prepare\PrepareRange;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Exceptions\Procurement\Prepare\PreparationRecipeMismatch;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Services\Procurement\Document\PreparePolicy;

/** Page-preserving output PDF extraction, never sidecar-only text. Original bytes stay immutable. */
final readonly class PageBatchOcr implements PdfPreparationTools
{
    public function __construct(private BoundedToolProcess $process, private PreparePolicy $policy) {}

    public function pageCount(string $source, string $directory, ExecutionContext $execution, float $deadline): int
    {
        $info = $this->run(['pdfinfo', $source], $directory, $execution, $deadline);
        if (preg_match('/^Pages:\s+(\d+)$/m', $info, $m) !== 1 || (int) $m[1] < 1) {
            throw new ToolFailed('invalid_pdf_page_count');
        }
        // Recipe carries actual tool identities; changing tools requires a new preparation recipe.
        $versions = [];
        foreach ([['qpdf', '--version'], ['ocrmypdf', '--version'], ['tesseract', '--version'], ['pdftotext', '-v'], ['pdfinfo', '-v']] as $command) {
            $versions[] = explode("\n", trim($this->process->run($command, $directory, $execution, $deadline, $this->policy->tmpBytes, includeStderr: true)), 2)[0];
        }
        $actual = implode(' ', $versions);
        if ($actual !== $this->policy->toolsVersion) {
            throw new PreparationRecipeMismatch('Installed tool versions differ from the sealed preparation recipe.');
        }

        return (int) $m[1];
    }

    public function batch(string $source, string $directory, PrepareRange $range, ExecutionContext $execution, float $deadline): PdfBatchText
    {
        $started = hrtime(true);
        $prefix = $directory.'/batch-'.bin2hex(random_bytes(6));
        $pdf = $prefix.'.pdf';
        $ocr = $prefix.'-ocr.pdf';
        $this->run(['qpdf', $source, '--pages', '.', ($range->start + 1).'-'.$range->end, '--', $pdf], $directory, $execution, $deadline);
        $native = $this->text($pdf, $range->length(), $directory, $execution, $deadline);
        $this->run(['ocrmypdf', '--skip-text', '--jobs', '1', '--output-type', 'pdf', '--language', $this->policy->languages,
            '--tesseract-timeout', (string) $this->policy->tesseractTimeout, '--sidecar', $prefix.'.sidecar', $pdf, $ocr], $directory, $execution, $deadline);
        $all = $this->text($ocr, $range->length(), $directory, $execution, $deadline);
        $pages = [];
        $text = '';
        foreach ($all as $i => $pageText) {
            $page = $range->start + $i + 1;
            $status = trim($native[$i]) !== '' ? PageTextStatus::NativeText : (trim($pageText) !== '' ? PageTextStatus::OcrText : PageTextStatus::Unrecognized);
            // Never claim blank from empty extraction; skip/timeout with exit0 stays unrecognized.
            $pages[] = new PreparePage($page, $status);
            $text .= '[page:'.$page."]\n".($status === PageTextStatus::NativeText ? $native[$i] : $pageText)."\n";
        }

        return new PdfBatchText($text, $pages, max(.001, (hrtime(true) - $started) / 1e9));
    }

    /** @return non-empty-list<string> */
    private function text(string $pdf, int $count, string $dir, ExecutionContext $ctx, float $deadline): array
    {
        $output = $this->run(['pdftotext', '-layout', '-enc', 'UTF-8', $pdf, '-'], $dir, $ctx, $deadline);
        $pages = explode("\f", $output);
        if (end($pages) === '') {
            array_pop($pages);
        }
        if (count($pages) !== $count || $pages === []) {
            throw new ToolFailed('page_mapping_mismatch');
        }

        return $pages;
    }

    /** @param non-empty-list<string> $command */
    private function run(array $command, string $dir, ExecutionContext $ctx, float $deadline): string
    {
        return $this->process->run($command, $dir, $ctx, $deadline, $this->policy->tmpBytes);
    }
}
