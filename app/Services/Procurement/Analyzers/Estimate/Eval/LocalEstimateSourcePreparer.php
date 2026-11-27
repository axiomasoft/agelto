<?php

declare(strict_types=1);

namespace App\Services\Procurement\Analyzers\Estimate\Eval;

use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\PageSourceManifest;
use App\Dto\Procurement\Document\Prepare\PrepareRange;
use App\Dto\Procurement\Process\Execution\CheckpointResult;
use App\Dto\Procurement\Process\Execution\ExecutionContext;
use App\Dto\Procurement\Process\Execution\ExecutionLease;
use App\Dto\Procurement\Process\Execution\LeaseRenewal;
use App\Dto\Procurement\Process\Outcomes\StageCheckpoint;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\DbInstant;
use App\Dto\Procurement\Process\Primitives\Duration;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Procurement\Process\Core\StageCode;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRepresentation;
use App\Services\Procurement\Fixture\FixtureManifestReader;
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\Spreadsheet\DeterministicSpreadsheetEstimateParser;
use App\Services\Procurement\Prepare\Tools\PdfPreparationTools;
use App\Services\Procurement\Prepare\Tools\TextGridRenderer;
use App\Services\Procurement\Process\Contracts\LeaseKeeper;
use App\Services\Procurement\Process\Contracts\ProcessClock;
use App\Support\Time\Monotonic;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Разрешает локальный файл или код эталона (.pdf/.xlsx/.xls/.gsfx) и готовит
 * постранично-размеченный текст + PageSourceManifest для CLI `estimate:parse`.
 */
final readonly class LocalEstimateSourcePreparer
{
    public function __construct(
        private FixtureManifestReader $manifestReader,
        private AnalysisRepresentation $representation,
        private TextGridRenderer $textGridRenderer,
        private DeterministicSpreadsheetEstimateParser $spreadsheetParser,
        private GsfxReader $gsfxReader,
    ) {}

    /**
     * @return array{path: string, format: string, gold_path: ?string}|null
     */
    public function resolveSource(string $sourceArg): ?array
    {
        if (is_file($sourceArg)) {
            $realPath = realpath($sourceArg);
            if ($realPath === false) {
                return null;
            }
            $ext = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'xlsx', 'xls', 'gsfx'], true)) {
                return null;
            }

            return ['path' => $realPath, 'format' => $ext, 'gold_path' => null];
        }

        $manifestPath = config('ai.fixtures.manifest_path');
        if (! is_string($manifestPath) || ! is_file($manifestPath)) {
            return null;
        }

        $manifest = $this->manifestReader->read($manifestPath);
        foreach ($manifest['fixtures'] as $entry) {
            if (! is_array($entry) || ($entry['code'] ?? null) !== $sourceArg) {
                continue;
            }
            $format = is_string($entry['source_format'] ?? null) ? strtolower($entry['source_format']) : '';
            if (! in_array($format, ['pdf', 'xlsx', 'xls', 'gsfx'], true)) {
                return null;
            }
            $goldPath = is_string($entry['gold_path'] ?? null) && is_file(base_path($entry['gold_path']))
                ? base_path($entry['gold_path'])
                : null;

            $sourceDir = config('ai.fixtures.source_dir');
            if (is_string($sourceDir) && is_string($entry['source_path'] ?? null)) {
                $candidate = rtrim($sourceDir, '/').'/'.$entry['source_path'];
                if (is_file($candidate)) {
                    return ['path' => (string) realpath($candidate), 'format' => $format, 'gold_path' => $goldPath];
                }
            }

            $disk = config('ai.fixtures.disk');
            if (is_string($disk) && is_string($entry['storage_path'] ?? null) && Storage::disk($disk)->exists($entry['storage_path'])) {
                $diskPath = Storage::disk($disk)->path($entry['storage_path']);
                if (is_file($diskPath)) {
                    return ['path' => (string) realpath($diskPath), 'format' => $format, 'gold_path' => $goldPath];
                }
            }
        }

        return null;
    }

    /**
     * @return array{anchored: string, hash: ContentHash, manifest: PageSourceManifest}
     */
    public function prepareDocument(string $path, string $format): array
    {
        $rawText = match ($format) {
            'pdf' => $this->preparePdf($path),
            'xlsx', 'xls' => $this->prepareSpreadsheet($path, $format),
            'gsfx' => $this->prepareGsfx($path),
            default => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };

        $hash = ContentHash::of($rawText);
        $pages = $this->representation->pages($rawText);
        $pageProofs = [];
        foreach ($pages as $index => $content) {
            $pageProofs[] = [
                'page' => $index + 1,
                'status' => trim($content) === '' ? 'proved_blank' : 'native_text',
            ];
        }
        $coverageJson = json_encode([
            'unit' => 'page',
            'source_units' => count($pages),
            'covered' => [[0, count($pages)]],
            'missing' => [],
            'pages' => $pageProofs,
        ], JSON_THROW_ON_ERROR);

        $manifest = $this->representation->source($rawText, $hash, $coverageJson);
        $anchored = $this->representation->anchored($rawText, new PageRange(0, $manifest->pageCount));

        return [
            'anchored' => $anchored,
            'hash' => $hash,
            'manifest' => $manifest,
        ];
    }

    private function preparePdf(string $path): string
    {
        $tmpDir = sys_get_temp_dir().'/agelto_parse_'.bin2hex(random_bytes(6));
        if (! mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            throw new \RuntimeException('Unable to create temporary preparation directory.');
        }

        try {
            $context = $this->syntheticExecutionContext(
                new WorkId(new ProcessUuid((string) Str::uuid())),
                ContentHash::of($path),
            );
            $deadline = Monotonic::seconds() + 180.0;
            $pdfTools = app(PdfPreparationTools::class);
            $pageCount = $pdfTools->pageCount($path, $tmpDir, $context, $deadline);
            $batch = $pdfTools->batch($path, $tmpDir, new PrepareRange(0, $pageCount), $context, $deadline);

            return $batch->anchoredText;
        } finally {
            foreach (glob($tmpDir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tmpDir);
        }
    }

    private function prepareSpreadsheet(string $path, string $format): string
    {
        $rendered = $this->textGridRenderer->render($path, $format);
        if (is_array($rendered) && trim($rendered['text']) !== '') {
            return "[page:1]\n".$rendered['text']."\n";
        }

        if ($format === 'xlsx') {
            $parsed = $this->spreadsheetParser->parse($path, 'xlsx');
            $grid = $parsed['grid'] ?? null;
            if (is_string($grid) && trim($grid) !== '') {
                return "[page:1]\n".$grid."\n";
            }
        }

        throw new \RuntimeException('Не удалось извлечь текстовую сетку из таблицы.');
    }

    private function prepareGsfx(string $path): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Не удалось открыть GSFX ZIP-контейнер.');
        }

        try {
            $dataXml = $zip->getFromName('Data.xml');
            if (! is_string($dataXml) || $dataXml === '') {
                throw new \RuntimeException('В GSFX-контейнере отсутствует Data.xml.');
            }
            $properties = $zip->getFromName('Properties.txt');
            $doc = $this->gsfxReader->parse(is_string($properties) ? $properties : '', $dataXml);
        } finally {
            $zip->close();
        }

        $lines = [];
        if ($doc->passport->object_name !== null && $doc->passport->object_name !== '') {
            $lines[] = 'OBJECT: '.$doc->passport->object_name;
        }
        foreach ($doc->chapters as $chapter) {
            $lines[] = '## CHAPTER: '.$chapter->caption;
            foreach ($chapter->positions as $pos) {
                $lines[] = implode("\t", array_filter([
                    $pos->number ?? '',
                    $pos->code ?? '',
                    $pos->name,
                    $pos->unit ?? '',
                    $pos->quantity ?? '',
                    $pos->price_curr ?? $pos->price_base ?? '',
                ], static fn (string $v): bool => $v !== ''));
            }
        }

        if ($lines === []) {
            $lines[] = '(empty gsfx document)';
        }

        return "[page:1]\n".implode("\n", $lines)."\n";
    }

    public function syntheticExecutionContext(WorkId $workId, ContentHash $inputHash): ExecutionContext
    {
        $clock = new class implements ProcessClock
        {
            public function dbNow(): DbInstant
            {
                return new DbInstant(new \DateTimeImmutable);
            }

            public function monotonicNs(): int
            {
                return Monotonic::nanoseconds();
            }

            public function sleep(Duration $duration): void
            {
                usleep($duration->milliseconds * 1000);
            }
        };
        $keeper = new class implements LeaseKeeper
        {
            public function heartbeat(ExecutionLease $lease): LeaseRenewal
            {
                return LeaseRenewal::renewed(new DbInstant(new \DateTimeImmutable('+360 seconds')));
            }

            public function checkpoint(ExecutionLease $lease, StageCheckpoint $checkpoint, int $expectedSeq): CheckpointResult
            {
                throw new \LogicException('Checkpoint not supported in CLI.');
            }
        };
        $lease = new ExecutionLease(
            $workId,
            new ProcessUuid((string) Str::uuid()),
            1,
            new ProcessUuid((string) Str::uuid()),
            new \DateTimeImmutable('+360 seconds'),
            1,
            $inputHash,
            0,
            null,
            StageCode::Screening,
            'cli-parse-estimate/1',
        );

        return new ExecutionContext(
            $lease,
            $keeper,
            $clock,
            Duration::seconds(240),
            Duration::seconds(360),
            new Duration(100),
            Duration::seconds(30),
            Duration::seconds(30),
        );
    }
}
