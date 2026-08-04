<?php

declare(strict_types=1);

namespace App\Services\Procurement\Bench;

use App\Dto\Procurement\Bench\BenchCellResult;
use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\AnalysisStatus;
use App\Enums\Procurement\Ai\AnalysisStep;
use App\Enums\Procurement\Ai\GoldSource;
use App\Enums\Procurement\Ai\RunMode;
use App\Enums\Procurement\Analyzers\Estimate\EstimateLlmPurpose;
use App\Enums\Procurement\Analyzers\Estimate\EstimatePromptStep;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Estimate\ExtractionRoute;
use App\Models\Llm\LlmCall;
use App\Models\Procurement\Ai\DatasetExample;
use App\Models\Procurement\Ai\Fixture;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Ai\AnalysisRunStoreRepository;
use App\Repositories\Procurement\Ai\AnalysisStoreRepository;
use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Llm\Dto\ResolvedRoute;
use App\Services\Llm\LlmRouteResolver;
use App\Services\Llm\PromptComposer;
use App\Services\Procurement\Analyzers\Estimate\EvalMetrics;
use App\Services\Procurement\Analyzers\Estimate\ExtractionOrchestrator;
use App\Services\Procurement\Extraction\ExtractionPreflight;
use App\Services\Procurement\Prepare\LibreOfficeConverter;
use App\Services\Procurement\Prepare\RepresentationResolver;
use App\Services\Procurement\Screening\PdfPageSlicer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Harness бенчмарка форматов входа (P6.1, D3/D48–D52): для каждого эталона с ДЕТЕРМИНИРОВАННЫМ
 * gold и каждого варианта представления собирает ячейку `BenchCellResult` — объём/цену
 * (детерминированно, БЕЗ LLM-вызова) и, вне `--dry-run`, качество/поведение реального прогона
 * `ExtractionOrchestrator` через сейм принуждения `$forceKind` (D50).
 *
 * Guard связи с эталоном (§5 root/document-contour.md, Implementation Rule 1 P1.6: документ
 * обязан быть рабочей копией ИМЕННО этого эталона, `$fixture->document_id === $document->id`) —
 * бенчмарк сбрасывает статусы документа/анализа перед прогоном ячейки, поэтому прогон по чужому
 * документу уничтожил бы его разбор; отказ по фикстуре НЕ прерывает остальной прогон (падение
 * одной ячейки не отменяет остальные), причина копится в `rejected` итогового массива.
 */
final readonly class FormatBenchRunner
{
    /**
     * Кандидаты вариантов ячейки, ОДИН и тот же порядок для каждого эталона (D48) — `pdf_alt`
     * добавляется в матрицу как not_applicable, если исходник не табличный/конвертер недоступен,
     * а не пропускается молча (единообразие строк для jq-инструментария P6.2).
     *
     * @var list<string>
     */
    private const array VARIANTS = [
        'default',
        'pdf',
        'pdf_ocr',
        'text',
        'text_grid',
        'structured_xml',
        'pdf_alt',
    ];

    private const array TABULAR_EXTENSIONS = ['xls', 'xlsx'];

    public function __construct(
        private FixtureStoreRepository $fixtures,
        private RepresentationStoreRepository $representations,
        private RepresentationResolver $resolver,
        private AnalysisStoreRepository $analyses,
        private AnalysisRunStoreRepository $runs,
        private DocumentStoreRepository $documents,
        private LlmRouteResolver $routes,
        private ExtractionPreflight $preflight,
        private ExtractionOrchestrator $orchestrator,
        private EvalMetrics $metrics,
        private LibreOfficeConverter $libreOffice,
        private PdfPageSlicer $pageSlicer,
        private PromptComposer $prompts,
    ) {}

    /**
     * @param  list<string>  $onlyCodes
     * @param  list<string>  $onlyVariants
     * @return array{mode: string, generated_at: string, route: array{provider: string, model: string}, prompt_version_set: array<string, int>, cells: list<array<string, mixed>>, rejected: list<string>}
     */
    public function run(array $onlyCodes, array $onlyVariants, bool $dryRun, ?int $limit): array
    {
        $route = $this->routes->resolve(AiDocumentType::Estimate, EstimateLlmPurpose::ExtractRows);
        $promptVersionSet = $this->prompts->compose(EstimatePromptStep::ExtractionRows)->promptVersionSet;
        $libreOfficeAvailable = $this->libreOffice->version() !== null;

        $examples = $this->eligibleExamples($onlyCodes, $limit);

        $cells = [];
        $rejected = [];

        foreach ($examples as $example) {
            $fixture = $example->fixture;
            $version = $example->documentVersion;

            if ($fixture === null || $version === null) {
                $rejected[] = "{$example->id}: fixture/document_version отсутствует";

                continue;
            }

            $document = $fixture->document;

            if ($document === null || $document->id !== $version->document_id) {
                $rejected[] = "{$fixture->code}: документ #{$version->document_id} не является рабочей копией этого эталона (или отсутствует) — бенчмарк работает только по документам эталонов";

                continue;
            }

            $variants = $onlyVariants === [] ? self::VARIANTS : array_values(array_intersect(self::VARIANTS, $onlyVariants));

            foreach ($variants as $variant) {
                $cells[] = $this->buildCell(
                    fixture: $fixture,
                    example: $example,
                    document: $document,
                    version: $version,
                    variant: $variant,
                    route: $route,
                    promptVersionSet: $promptVersionSet,
                    dryRun: $dryRun,
                    libreOfficeAvailable: $libreOfficeAvailable,
                )->toArray();
            }
        }

        usort($cells, static fn (array $a, array $b): int => [$a['fixture_code'], $a['variant']] <=> [$b['fixture_code'], $b['variant']]);

        return [
            'mode' => $dryRun ? 'dry' : 'run',
            'generated_at' => now()->toIso8601String(),
            'route' => ['provider' => $route->route->provider->code, 'model' => $route->route->model],
            'prompt_version_set' => $promptVersionSet,
            'cells' => $cells,
            'rejected' => $rejected,
        ];
    }

    /**
     * @param  list<string>  $onlyCodes
     * @return Collection<int, DatasetExample>
     */
    private function eligibleExamples(array $onlyCodes, ?int $limit): Collection
    {
        $fixtures = $this->fixtures->all()
            ->filter(fn (Fixture $fixture): bool => $fixture->profileGoldSource() === GoldSource::Deterministic)
            ->when($onlyCodes !== [], fn ($collection) => $collection->filter(
                fn (Fixture $fixture): bool => in_array($fixture->code, $onlyCodes, strict: true),
            ))
            ->sortBy(fn (Fixture $fixture): string => $fixture->code)
            ->values();

        $examples = DatasetExample::query()
            ->where('task', 'extraction')
            ->whereIn('fixture_id', $fixtures->pluck('id'))
            ->with(['fixture', 'documentVersion'])
            ->get()
            ->sortBy(fn (DatasetExample $example): string => $example->fixture->code)
            ->values();

        return $limit !== null ? $examples->take($limit) : $examples;
    }

    private function buildCell(
        Fixture $fixture,
        DatasetExample $example,
        Document $document,
        DocumentVersion $version,
        string $variant,
        ResolvedRoute $route,
        array $promptVersionSet,
        bool $dryRun,
        bool $libreOfficeAvailable,
    ): BenchCellResult {
        $resolved = $this->resolveVariant($fixture, $version, $variant, $libreOfficeAvailable);

        if ($resolved === null) {
            return BenchCellResult::notApplicable($fixture->code, $variant);
        }

        [$effective, $facts] = $resolved;

        $localPath = tempnam(sys_get_temp_dir(), 'bench_').'.'.$effective['extension'];
        file_put_contents($localPath, Storage::disk($effective['disk'])->get($effective['storagePath']));

        try {
            if ($facts['page_count'] === null && $effective['extension'] === 'pdf') {
                $facts['page_count'] = $this->pageSlicer->pageCount($localPath);
            }

            if ($facts['token_estimate'] === null && $effective['extension'] !== 'pdf') {
                $facts['token_estimate'] = (int) ceil($facts['size_bytes'] / 4);
            }

            $decision = $this->preflight->decide($localPath, $effective['extension'], $route);

            $preflight = [
                'route' => match ($decision->route) {
                    ExtractionRoute::Whole => 'whole',
                    ExtractionRoute::Windowed => 'windowed',
                    null => 'unprocessable',
                },
                'reason' => $decision->unprocessableReason?->value,
            ];

            $inputTokens = $effective['extension'] === 'pdf'
                ? $facts['page_count'] * 258
                : (int) $facts['token_estimate'];

            $capabilities = (array) config('ai.capabilities', []);
            $pricing = $capabilities[$route->options->model] ?? null;
            $inputCostMicros = is_array($pricing) ? (int) ceil($inputTokens * (float) $pricing['input_per_1m']) : 0;

            $predicted = ['input_tokens' => $inputTokens, 'input_cost_micros' => $inputCostMicros];

            if ($dryRun) {
                return BenchCellResult::dry($fixture->code, $variant, $facts, $preflight, $predicted, $promptVersionSet);
            }

            return $this->runCell($fixture, $example, $document, $version, $variant, $facts, $preflight, $predicted, $promptVersionSet);
        } finally {
            @unlink($localPath);
        }
    }

    /**
     * Реальный прогон ячейки (P6.2 использует боевого провайдера; тесты этого item'а —
     * `FakeLlmProvider`, D48): сброс `Analysis`/статуса документа, граница вызовов по
     * `LlmCall::max('id')` (сброс `Analysis` не отделяет прогоны друг от друга — отделяет именно
     * граница по id), сравнение с gold через `EvalMetrics::compare()`.
     */
    private function runCell(
        Fixture $fixture,
        DatasetExample $example,
        Document $document,
        DocumentVersion $version,
        string $variant,
        array $facts,
        array $preflight,
        array $predicted,
        array $promptVersionSet,
    ): BenchCellResult {
        $forceKind = $variant === 'default' ? null : RepresentationKind::from($variant);
        $maxLlmCallId = (int) (LlmCall::query()->max('id') ?? 0);

        // Launch-точка (§3.5 root/document-contour.md) — КАЖДАЯ ячейка это отдельный прогон:
        // `Analysis` ниже сбрасывается и переиспользуется той же строкой (partial unique
        // `(document_version_id, step)`), поэтому `analysis_run_id` переставляется явно на
        // свежий прогон при сбросе — здесь это не «переиспользование через checkpoint-requeue»
        // (Implementation Rule 9), а полноценный новый запуск, harness управляет жизненным
        // циклом сам.
        $run = $this->runs->start($version->id, RunMode::Manual);

        $analysis = $this->analyses->firstOrCreate($version->id, AnalysisStep::Extraction);
        $this->analyses->update($analysis, [
            'status' => AnalysisStatus::Pending,
            'analysis_run_id' => $run->id,
            'document_type' => AiDocumentType::Estimate->value,
            'result' => null,
            'progress' => null,
            'provider' => null,
            'model' => null,
            'attempts' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
            'failed_permanently' => false,
            'started_at' => null,
            'finished_at' => null,
            'cache_id' => null,
            'cache_expires_at' => null,
        ]);
        $analysis->refresh();
        $this->documents->update($document, ['status' => DocumentStatus::Downloaded]);

        $outcome = ['status' => 'failed', 'last_error' => null];

        try {
            $maxIterations = (int) config('ai.eval.max_orchestrator_iterations', 200);
            $iterations = 0;

            do {
                $needsContinuation = $this->orchestrator->run($document, $analysis, $forceKind);
                $analysis->refresh();
                $iterations++;
            } while ($needsContinuation && $iterations < $maxIterations);

            $outcome = match ($analysis->status) {
                AnalysisStatus::Succeeded => ['status' => 'succeeded', 'last_error' => null],
                AnalysisStatus::Skipped => ['status' => 'unprocessable', 'last_error' => (string) ($analysis->result['detail'] ?? $analysis->result['unprocessable_reason'] ?? null)],
                default => ['status' => 'failed', 'last_error' => "analysis.status={$analysis->status->value}"],
            };
        } catch (Throwable $e) {
            $outcome = ['status' => 'failed', 'last_error' => $e->getMessage()];
        }

        // Подстраховка терминации (§3.6): исход `orchestrator->run()` бросил ДО терминального
        // `analyses->update()` (catch-ветка) либо цикл упёрся в `$maxIterations` — прогон не
        // терминировался автохуком `AnalysisStoreRepository::update()`. Идемпотентно: если
        // терминал уже проставлен автохуком, `markSucceeded()`/`markFailed()` — no-op.
        match ($outcome['status']) {
            'succeeded', 'unprocessable' => $this->runs->markSucceeded($run),
            default => $this->runs->markFailed($run),
        };

        $calls = LlmCall::query()
            ->where('id', '>', $maxLlmCallId)
            ->where('analysis_id', $analysis->id)
            ->get();

        $actual = [
            'prompt_tokens' => (int) $calls->sum('prompt_tokens'),
            'cached_tokens' => (int) $calls->sum('cached_tokens'),
            'completion_tokens' => (int) $calls->sum('completion_tokens'),
            'total_tokens' => (int) $calls->sum('total_tokens'),
            'cost_micros' => (int) $calls->sum('cost_micros'),
            'calls' => $calls->count(),
            'failed_calls' => $calls->where('success', false)->count(),
            'duration_ms' => (int) $calls->sum('duration_ms'),
        ];

        $quality = null;

        if ($outcome['status'] === 'succeeded') {
            $predictedEstimates = (array) ($analysis->result['estimates'] ?? []);
            $goldEstimates = (array) ($example->gold['estimates'] ?? []);
            $quality = $this->metrics->compare(goldEstimates: $goldEstimates, predictedEstimates: $predictedEstimates);
        }

        return BenchCellResult::executed($fixture->code, $variant, $facts, $preflight, $predicted, $promptVersionSet, $actual, $quality, $outcome);
    }

    /**
     * @return array{0: array{storagePath: string, disk: string, extension: string, mime: string}, 1: array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}}|null
     */
    private function resolveVariant(Fixture $fixture, DocumentVersion $version, string $variant, bool $libreOfficeAvailable): ?array
    {
        if ($variant === 'default') {
            $effective = $this->resolver->forLlm($version);
            $pdfRepresentation = $this->representations->find($version, RepresentationKind::Pdf);

            if ($pdfRepresentation !== null) {
                return [$effective, $this->factsFromRepresentation($pdfRepresentation)];
            }

            // Оригинал версии без представления `pdf` (сегодня — только gsfx-эталоны без
            // `LibreOfficeConverter`-стратегии для этого источника): `kind=null` — записи о нём
            // в `prc_document_representations` нет (D25 сознательно не заводит kind `original`).
            return [$effective, [
                'kind' => null,
                'tool' => null,
                'size_bytes' => $version->size_bytes,
                'page_count' => null,
                'token_estimate' => null,
            ]];
        }

        if ($variant === 'pdf_alt') {
            return $this->resolvePdfAlt($fixture, $version, $libreOfficeAvailable);
        }

        $kind = RepresentationKind::from($variant);
        $representation = $this->representations->find($version, $kind);

        if ($representation === null) {
            return null;
        }

        $effective = $this->resolver->forLlm($version, $kind);

        if ($effective === null) {
            return null;
        }

        return [$effective, $this->factsFromRepresentation($representation)];
    }

    /**
     * @return array{0: array{storagePath: string, disk: string, extension: string, mime: string}, 1: array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}}|null
     */
    private function resolvePdfAlt(Fixture $fixture, DocumentVersion $version, bool $libreOfficeAvailable): ?array
    {
        if (! in_array($version->extension, self::TABULAR_EXTENSIONS, true) || ! $libreOfficeAvailable) {
            return null;
        }

        $existing = $this->representations->find($version, RepresentationKind::PdfAlt);

        if ($existing === null) {
            $existing = $this->producePdfAlt($fixture, $version);

            if ($existing === null) {
                return null;
            }
        }

        $effective = $this->resolver->forLlm($version, RepresentationKind::PdfAlt);

        if ($effective === null) {
            return null;
        }

        return [$effective, $this->factsFromRepresentation($existing)];
    }

    private function producePdfAlt(Fixture $fixture, DocumentVersion $version): ?DocumentRepresentation
    {
        $documentsDisk = (string) config('crawler.documents_disk');
        $fixturesDisk = (string) config('ai.fixtures.disk');
        $localInput = tempnam(sys_get_temp_dir(), 'bench_pdf_alt_').'.'.$version->extension;
        $outputDir = sys_get_temp_dir();

        try {
            file_put_contents($localInput, Storage::disk($documentsDisk)->get($version->storage_path));

            $pdfPath = $this->libreOffice->toPdf($localInput, $outputDir);
            $pdfContent = (string) file_get_contents($pdfPath);
            @unlink($pdfPath);

            $storagePath = Fixture::derivedPathFor($fixture->code, 'bench/pdf_alt.pdf');
            Storage::disk($fixturesDisk)->put($storagePath, $pdfContent);

            return $this->representations->storeOrUpdate($version, RepresentationKind::PdfAlt, [
                'storage_path' => $storagePath,
                'disk' => $fixturesDisk,
                'size_bytes' => strlen($pdfContent),
                'mime' => 'application/pdf',
                'meta' => ['tool' => 'libreoffice', 'origin' => 'bench', 'tool_version' => $this->libreOffice->version()],
                'generated_at' => now(),
            ]);
        } catch (Throwable) {
            return null;
        } finally {
            @unlink($localInput);
        }
    }

    /**
     * @return array{kind: ?string, tool: ?string, size_bytes: ?int, page_count: ?int, token_estimate: ?int}
     */
    private function factsFromRepresentation(DocumentRepresentation $representation): array
    {
        return [
            'kind' => $representation->kind->value,
            'tool' => (string) ($representation->meta['tool'] ?? null) ?: null,
            'size_bytes' => $representation->size_bytes,
            'page_count' => $representation->page_count,
            'token_estimate' => $representation->token_estimate,
        ];
    }
}
