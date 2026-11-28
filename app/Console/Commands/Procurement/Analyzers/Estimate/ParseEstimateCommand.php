<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Analyzers\Estimate;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\Planning\CoverageStrategy;
use App\Dto\Procurement\Ai\Planning\PageRange;
use App\Dto\Procurement\Ai\Planning\PageSourceManifest;
use App\Dto\Procurement\Process\Primitives\ContentHash;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Dto\Procurement\Process\Primitives\WorkId;
use App\Enums\Llm\AuthHeaderScheme;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Llm\TaskPurpose;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Repositories\Procurement\Network\SourceCatalogRepository;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Execution\SendClaimResult;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\CanonicalSchemaValidator;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Analyzers\Estimate\Eval\EvalMetrics;
use App\Services\Procurement\Analyzers\Estimate\Eval\LocalEstimateSourcePreparer;
use App\Services\Procurement\Extraction\Orchestration\AnalysisConfiguration;
use App\Services\Procurement\Extraction\Orchestration\AnalysisRecipeFactory;
use App\Services\Procurement\Extraction\Pipeline\UntrustedDocumentSanitizer;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderSendGate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Локальный CLI-инструмент разбора сметы (.pdf/.xlsx/.xls/.gsfx или код эталона).
 * По умолчанию работает в режиме --dry-run (ноль HTTP, ноль расходов).
 * Режим --live требует явного --cap= и активного provisioned-подключения, исполняясь строго через
 * RequestLedger + ReceiptStore + StructuredCallExecutor + AnalysisProviderSendGate.
 */
#[Description('Разобрать смету локально (--dry-run по умолчанию, --live через канонический леджер)')]
#[AsCommand(name: 'estimate:parse')]
#[Signature('estimate:parse
        {source : Path to .pdf/.xlsx/.xls/.gsfx file or fixture code}
        {--profile= : Explicit LLM profile from config/llm.php}
        {--dry-run : Compile wire & inspect plan without HTTP}
        {--live : Execute through canonical RequestLedger + AnalysisProviderSendGate}
        {--cap= : Explicit USD budget cap required for --live}
        {--stage=all : screening|discovery|all}
        {--gold= : Optional path to .gold.json for EvalMetrics diff}
        {--json : Machine-readable JSON output}')]
final class ParseEstimateCommand extends Command
{
    private const int DEFAULT_WINDOW_SPAN = 10;

    private const int DEFAULT_OVERLAP = 2;

    public function __construct(
        private readonly LocalEstimateSourcePreparer $sourcePreparer,
        private readonly AnalysisRecipeFactory $recipeFactory,
        private readonly StructuredCallExecutor $executor,
        private readonly CanonicalSchemaValidator $schemaValidator,
        private readonly ProviderSchemaCompiler $schemaCompiler,
        private readonly EvalMetrics $evalMetrics,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $live = (bool) $this->option('live');
        $stage = $this->optionString('stage', 'all');
        if (! in_array($stage, ['screening', 'discovery', 'all'], true)) {
            $this->error("Недопустимый --stage={$stage} (ожидается screening|discovery|all).");

            return self::FAILURE;
        }

        $sourceArg = $this->argument('source');
        $resolved = $this->sourcePreparer->resolveSource($sourceArg);
        if ($resolved === null) {
            $this->error("Источник не найден или формат не поддерживается: {$sourceArg}");

            return self::FAILURE;
        }

        try {
            $prepared = $this->sourcePreparer->prepareDocument($resolved['path'], $resolved['format']);
        } catch (\Throwable $failure) {
            $this->error('Ошибка подготовки документа: '.$failure->getMessage());

            return self::FAILURE;
        }

        $manifest = $prepared['manifest'];
        $wholeRange = new PageRange(0, $manifest->pageCount);
        $coverage = new CoverageStrategy(
            windowSpan: min(self::DEFAULT_WINDOW_SPAN, max(1, $manifest->pageCount)),
            overlap: $manifest->pageCount > 2 ? min(self::DEFAULT_OVERLAP, $manifest->pageCount - 1) : 0,
        );
        $windows = $this->planWindows($wholeRange, $coverage);

        if (! $live) {
            return $this->runDryRun(
                format: $resolved['format'],
                sourcePath: $resolved['path'],
                anchoredText: $prepared['anchored'],
                preparationHash: $prepared['hash'],
                manifest: $manifest,
                coverage: $coverage,
                windows: $windows,
                stage: $stage,
            );
        }

        return $this->runLive(
            format: $resolved['format'],
            sourcePath: $resolved['path'],
            anchoredText: $prepared['anchored'],
            preparationHash: $prepared['hash'],
            manifest: $manifest,
            coverage: $coverage,
            windows: $windows,
            stage: $stage,
            defaultGoldPath: $resolved['gold_path'],
        );
    }

    /**
     * @param  list<PageRange>  $windows
     */
    private function runDryRun(
        string $format,
        string $sourcePath,
        string $anchoredText,
        ContentHash $preparationHash,
        PageSourceManifest $manifest,
        CoverageStrategy $coverage,
        array $windows,
        string $stage,
    ): int {
        $profileOption = $this->optionNullableString('profile');

        try {
            $target = $this->resolveDryRunProfile($profileOption);
        } catch (\Throwable $failure) {
            $this->error('Не удалось разрешить LLM-профиль для --dry-run: '.$failure->getMessage());

            return self::FAILURE;
        }

        $recipe = $this->recipeFactory->make($preparationHash, $target['context'], $coverage);
        foreach ($recipe->tasks->tasks as $task) {
            $this->schemaValidator->assertSchema($task->schema);
        }

        $screeningSnapshot = $recipe->tasks->task(TaskPurpose::Screening);
        $providerSchema = $this->schemaCompiler->compile($screeningSnapshot->schema);
        $token = substr(hash('sha256', $recipe->hash->hex.':'.$preparationHash->hex), 0, 12);
        $document = (new UntrustedDocumentSanitizer)->wrap($anchoredText, $token);
        $user = $document."\n\n".CanonicalJson::encode(['semantic_reask_ordinal' => 0]);

        $plan = new ProviderOperationPlan(
            identity: new RequestIdentity(
                recipeHash: $recipe->hash->hex,
                inputHash: $preparationHash->hex,
                purpose: OperationPurpose::Generate,
                semanticReaskOrdinal: 0,
                replayIntentId: (string) Str::uuid(),
            ),
            context: $target['context'],
            pricing: $target['pricing'],
            consumerCorrelationId: 'cli-dry-run',
            generation: new ProviderGenerationInput(
                system: $screeningSnapshot->prompt,
                user: $user,
                schema: $providerSchema,
                purpose: TaskPurpose::Screening,
            ),
        );

        $wire = $this->executor->compileGeneration($plan, $target['origin']);
        $authHeader = $this->redactAuthScheme($wire->authScheme);
        $plannedCalls = match ($stage) {
            'screening' => 1,
            'discovery' => 2,
            default => 2 + count($windows),
        };
        $totalUpperBound = bcmul($target['pricing']->upperBound, (string) $plannedCalls, 8);

        $report = [
            'mode' => 'dry-run',
            'source' => $sourcePath,
            'format' => $format,
            'page_count' => $manifest->pageCount,
            'char_count' => strlen($anchoredText),
            'provider' => $target['context']->provider,
            'model' => $target['context']->model,
            'endpoint_url' => $wire->endpoint,
            'auth_header_scheme' => $authHeader,
            'stage' => $stage,
            'window_plan' => [
                'window_span' => $coverage->windowSpan,
                'overlap' => $coverage->overlap,
                'window_count' => count($windows),
                'ranges' => array_map(static fn (PageRange $r): array => $r->fields(), $windows),
            ],
            'budget_upper_bound' => [
                'currency' => $target['pricing']->currency,
                'per_call' => $target['pricing']->upperBound,
                'planned_calls' => $plannedCalls,
                'total_upper_bound' => $totalUpperBound,
            ],
            'wire_body_sha256' => $wire->bodyHash,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('estimate:parse [dry-run] — 0 HTTP, 0 USD');
        $this->table(
            ['Параметр', 'Значение'],
            [
                ['Формат', $format],
                ['Страниц', (string) $manifest->pageCount],
                ['Символов (anchored)', (string) strlen($anchoredText)],
                ['Провайдер', $target['context']->provider],
                ['Модель', $target['context']->model],
                ['Endpoint URL', $wire->endpoint],
                ['Auth Header', $authHeader],
                ['Окон (plan)', count($windows)." (span={$coverage->windowSpan}, overlap={$coverage->overlap})"],
                ['Бюджет upper bound (call)', $target['pricing']->upperBound.' '.$target['pricing']->currency],
                ['Бюджет upper bound (total)', $totalUpperBound.' '.$target['pricing']->currency." ({$plannedCalls} calls)"],
            ],
        );

        return self::SUCCESS;
    }

    /**
     * @param  list<PageRange>  $windows
     */
    private function runLive(
        string $format,
        string $sourcePath,
        string $anchoredText,
        ContentHash $preparationHash,
        PageSourceManifest $manifest,
        CoverageStrategy $coverage,
        array $windows,
        string $stage,
        ?string $defaultGoldPath,
    ): int {
        $capRaw = $this->optionNullableString('cap');
        if ($capRaw === null || ! is_numeric($capRaw) || (float) $capRaw <= 0.0) {
            $this->error('Режим --live требует явного положительного --cap=<USD>.');

            return self::FAILURE;
        }
        $capUsd = number_format((float) $capRaw, 8, '.', '');

        $profileOption = $this->optionNullableString('profile');

        try {
            $connection = $this->resolveActiveConnection($profileOption);
        } catch (\Throwable $failure) {
            $this->error('Нет активного provisioned-подключения: '.$failure->getMessage());

            return self::FAILURE;
        }

        $targetRow = app(SourceCatalogRepository::class)->targetForCall(
            platformId: null,
            providerKey: $connection->context->provider,
            capability: 'provider',
            origin: $connection->origin,
        );
        if ($targetRow === null) {
            $this->error('Не зарегистрирован target в prc_source_targets для выбранного провайдера/origin.');

            return self::FAILURE;
        }

        $finance = app(FinancialReservationRepository::class);
        try {
            $periodId = $connection->periodId !== ''
                ? $connection->periodId
                : $finance->currentPeriod($connection->context->account, $connection->pricing->currency);
        } catch (OperationDenied $denied) {
            $this->error('Бюджетный период недоступен: '.$denied->getMessage());

            return self::FAILURE;
        }

        $recipe = $this->recipeFactory->make($preparationHash, $connection->context, $coverage);
        $ledger = app(RequestLedger::class);
        $receipts = app(ReceiptStore::class);
        $requests = app(RequestRepository::class);
        $validator = app(StructuredTaskValidator::class);

        $runId = new ProcessUuid((string) Str::uuid());
        $workId = new WorkId(new ProcessUuid((string) Str::uuid()));
        $targetId = new ProcessUuid($targetRow->string('id'));
        $executionContext = $this->sourcePreparer->syntheticExecutionContext($workId, $preparationHash);

        $gate = app()->make(AnalysisProviderSendGate::class, [
            'context' => $executionContext,
            'runId' => $runId,
            'target' => $targetId,
            'profile' => $connection->profile,
            'urlPolicy' => $connection->urlPolicy,
            'apiKey' => $connection->credential(),
        ]);

        $token = substr(hash('sha256', $recipe->hash->hex.':'.$preparationHash->hex), 0, 12);
        $sanitizer = new UntrustedDocumentSanitizer;
        $totalCost = '0.00000000';
        $usageTotals = ['uncached_input' => 0, 'cached_input' => 0, 'output' => 0, 'thinking' => 0];
        $extractedEstimates = [];

        $tasksToRun = $stage === 'screening'
            ? [[TaskPurpose::Screening, new PageRange(0, $manifest->pageCount)]]
            : ($stage === 'discovery'
                ? [[TaskPurpose::Screening, new PageRange(0, $manifest->pageCount)], [TaskPurpose::Discovery, new PageRange(0, $manifest->pageCount)]]
                : array_merge(
                    [[TaskPurpose::Screening, new PageRange(0, $manifest->pageCount)], [TaskPurpose::Discovery, new PageRange(0, $manifest->pageCount)]],
                    array_map(static fn (PageRange $w): array => [TaskPurpose::Rows, $w], $windows),
                ));

        foreach ($tasksToRun as $ordinal => [$purpose, $range]) {
            if (bccomp(bcadd($totalCost, $connection->pricing->upperBound, 8), $capUsd, 8) > 0) {
                $this->error("Превышен --cap={$capUsd} USD до запуска {$purpose->value}.");

                return self::FAILURE;
            }

            $snapshot = $recipe->tasks->task($purpose);
            $rangeText = $purpose === TaskPurpose::Screening ? $anchoredText : $this->sliceAnchoredPages($anchoredText, $range);
            $user = $sanitizer->wrap($rangeText, $token)."\n\n".CanonicalJson::encode([
                'range' => $range->fields(),
                'semantic_reask_ordinal' => 0,
            ]);

            $inputHash = ContentHash::of($purpose->value.':'.$range->start.':'.$range->end.':'.$preparationHash->hex);
            $plan = new ProviderOperationPlan(
                identity: new RequestIdentity(
                    recipeHash: $recipe->hash->hex,
                    inputHash: $inputHash->hex,
                    purpose: OperationPurpose::Generate,
                    semanticReaskOrdinal: 0,
                    replayIntentId: $runId->value,
                ),
                context: $connection->context,
                pricing: $connection->pricing,
                consumerCorrelationId: $workId->value->value,
                generation: new ProviderGenerationInput(
                    system: $snapshot->prompt,
                    user: $user,
                    schema: $this->schemaCompiler->compile($snapshot->schema),
                    purpose: $purpose,
                ),
            );

            $wire = $this->executor->compileGeneration($plan, $connection->origin);
            $operation = $ledger->authorize(
                plan: $plan,
                attempt: (string) Str::uuid(),
                generation: 1,
                periodId: $periodId,
                ids: new ProviderOperationIds(
                    logicalRequestId: (string) Str::uuid(),
                    physicalCallId: (string) Str::uuid(),
                    receiptId: (string) Str::uuid(),
                    reservationId: (string) Str::uuid(),
                    authorizationToken: (string) Str::uuid(),
                ),
                wire: $wire,
            );

            $outcome = $this->executor->execute($operation, $wire, $gate);
            if ($outcome instanceof SendClaimResult) {
                $this->error('Запрос отклонён SendGate: '.$outcome->denial?->value);

                return self::FAILURE;
            }

            $call = $requests->get($operation->physicalCallId);
            $receipt = $receipts->inspect($call);
            if ($receipt === null || $receipt->assessment->outcome !== ReceiptOutcome::Success) {
                $this->error("Провайдер вернул неуспешный receipt ({$call->outcome}).");

                return self::FAILURE;
            }

            $usage = $receipt->assessment->usage;
            if ($usage !== null) {
                $usageTotals['uncached_input'] += $usage->uncachedInput;
                $usageTotals['cached_input'] += $usage->cachedInput;
                $usageTotals['output'] += $usage->output;
                $usageTotals['thinking'] += $usage->thinking;
            }
            $settled = DB::table('llm_budget_reservations')->where('physical_call_id', $operation->physicalCallId)->value('settled_amount');
            if (is_string($settled) && is_numeric($settled)) {
                $totalCost = bcadd($totalCost, $settled, 8);
            }

            $decoded = $this->executor->decodeGeneration($receipt);
            if ($purpose === TaskPurpose::Rows) {
                $structured = json_decode($decoded->modelJson, true);
                if (is_array($structured)) {
                    /** @var array<string, mixed> $structured */
                    $extractedEstimates[] = $structured;
                }
            }
        }

        $goldPath = $this->optionNullableString('gold') ?? $defaultGoldPath;
        $evalDiff = null;
        if ($goldPath !== null && is_file($goldPath)) {
            $goldRaw = json_decode((string) file_get_contents($goldPath), true);
            /** @var list<array<string, mixed>> $goldEstimates */
            $goldEstimates = is_array($goldRaw) && is_array($goldRaw['estimates'] ?? null) ? array_values(array_filter($goldRaw['estimates'], 'is_array')) : [];
            /** @var list<array<string, mixed>> $extractedEstimates */
            $evalDiff = $this->evalMetrics->compare($goldEstimates, $extractedEstimates);
        }

        $resultPayload = [
            'mode' => 'live',
            'source' => $sourcePath,
            'format' => $format,
            'provider' => $connection->context->provider,
            'model' => $connection->context->model,
            'usage' => $usageTotals,
            'settled_cost_usd' => $totalCost,
            'estimates' => $extractedEstimates,
            'eval_diff' => $evalDiff,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($resultPayload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("estimate:parse [live] — settled {$totalCost} USD");
        $this->line('Tokens: uncached='.$usageTotals['uncached_input'].', cached='.$usageTotals['cached_input'].', output='.$usageTotals['output'].', thinking='.$usageTotals['thinking']);
        $this->line('Extracted chunks: '.count($extractedEstimates));
        if ($evalDiff !== null) {
            $this->line('EvalMetrics F1: '.$evalDiff['overall']['f1'].' (row_coverage='.$evalDiff['row_coverage'].')');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{context: PinnedLlmContext, pricing: PricingSnapshot, origin: string}
     */
    private function resolveDryRunProfile(?string $profileName): array
    {
        $connections = app(AnalysisProviderConnections::class);
        if ($profileName === null) {
            try {
                $preferred = $connections->preferred();

                return ['context' => $preferred->context, 'pricing' => $preferred->pricing, 'origin' => $preferred->origin];
            } catch (OperationDenied) {
                // Fall back to config('llm.analysis_profile') or first configured preset below.
            }
        }

        $configConnections = app(AnalysisConfiguration::class)->connections;
        $selectedName = $profileName ?? (is_string(config('llm.analysis_profile')) && config('llm.analysis_profile') !== '' ? config('llm.analysis_profile') : 'openai');
        if (isset($configConnections[$selectedName])) {
            $conn = $configConnections[$selectedName];

            return ['context' => $conn->context, 'pricing' => $conn->pricing, 'origin' => $conn->origin];
        }

        $presets = config('llm.profiles', []);
        $catalog = config('llm.model_pricing', []);
        if (! is_array($presets) || ! is_array($presets[$selectedName] ?? null)) {
            throw new \InvalidArgumentException("Unknown LLM profile: {$selectedName}");
        }
        /** @var array<string, mixed> $preset */
        $preset = $presets[$selectedName];
        $provider = is_string($preset['provider'] ?? null) ? $preset['provider'] : $selectedName;
        if (! ProviderCodecs::default()->has($provider)) {
            throw new \InvalidArgumentException("Provider has no wire codec: {$provider}");
        }
        $model = is_string($preset['model'] ?? null) ? $preset['model'] : '';
        $modelPricing = is_array($catalog) && is_array($catalog[$model] ?? null) ? $catalog[$model] : null;
        if ($modelPricing === null) {
            throw new \InvalidArgumentException("Missing model_pricing entry for {$model}");
        }
        $origin = is_string($preset['origin'] ?? null) && $preset['origin'] !== ''
            ? $preset['origin']
            : 'https://api.openai.com';

        $maxOutput = is_int($preset['max_output_tokens'] ?? null) ? $preset['max_output_tokens'] : 16384;
        $inputMax = is_int($preset['input_max_tokens'] ?? null) ? $preset['input_max_tokens'] : 128000;
        $thinkingMax = is_int($preset['thinking_max_tokens'] ?? null) ? $preset['thinking_max_tokens'] : 16384;
        $thinking = [];
        if (is_string($preset['reasoning_effort'] ?? null) && $preset['reasoning_effort'] !== '') {
            $thinking['effort'] = $preset['reasoning_effort'];
        } elseif (is_string($preset['thinking_level'] ?? null) && $preset['thinking_level'] !== '') {
            $thinking['level'] = $preset['thinking_level'];
        }

        $context = new PinnedLlmContext(
            provider: $provider,
            account: is_string($preset['account'] ?? null) ? $preset['account'] : $provider.'-dry-run',
            apiVersion: is_string($preset['api_version'] ?? null) ? $preset['api_version'] : 'v1',
            model: $model,
            routePolicyVersion: 'dry-run/1',
            options: ['max_output_tokens' => $maxOutput],
            thinking: $thinking,
        );

        $pricing = new PricingSnapshot(
            currency: is_string($modelPricing['currency'] ?? null) ? $modelPricing['currency'] : 'USD',
            version: 'catalog/1',
            purpose: OperationPurpose::Generate,
            inputMaxTokens: $inputMax,
            outputMaxTokens: $maxOutput,
            thinkingMaxTokens: $thinkingMax,
            thinkingIncludedInOutput: (bool) ($modelPricing['thinking_included_in_output'] ?? true),
            inputPerMillion: is_int($modelPricing['input_per_million'] ?? null) ? $modelPricing['input_per_million'] : 0,
            outputPerMillion: is_int($modelPricing['output_per_million'] ?? null) ? $modelPricing['output_per_million'] : 0,
            thinkingPerMillion: is_int($modelPricing['thinking_per_million'] ?? null) ? $modelPricing['thinking_per_million'] : 0,
            fixedCharge: 0,
            cacheCreateCharge: 0,
            storageTokenHoursMax: 0,
            storagePerTokenHour: 0,
            boundEvidence: 'config:llm.model_pricing:'.$model,
            validUntil: is_string($preset['pricing_valid_until'] ?? null) ? $preset['pricing_valid_until'] : '2027-12-31T23:59:59Z',
            cachedInputPerMillion: is_int($modelPricing['cached_input_per_million'] ?? null) ? $modelPricing['cached_input_per_million'] : null,
        );

        return ['context' => $context, 'pricing' => $pricing, 'origin' => $origin];
    }

    private function resolveActiveConnection(?string $profileName): AnalysisProviderConnection
    {
        if ($profileName !== null) {
            $connections = app(AnalysisConfiguration::class)->connections;
            if (! isset($connections[$profileName])) {
                throw new OperationDenied("profile_not_provisioned:{$profileName}");
            }

            return app(AnalysisProviderConnections::class)->for($connections[$profileName]->context);
        }

        return app(AnalysisProviderConnections::class)->preferred();
    }

    /**
     * @return list<PageRange>
     */
    private function planWindows(PageRange $whole, CoverageStrategy $coverage): array
    {
        $windows = [];
        $step = max(1, $coverage->windowSpan - $coverage->overlap);
        for ($start = $whole->start; $start < $whole->end; $start += $step) {
            $end = min($whole->end, $start + $coverage->windowSpan);
            $windows[] = new PageRange($start, $end);
            if ($end === $whole->end) {
                break;
            }
        }

        return $windows;
    }

    private function sliceAnchoredPages(string $anchoredText, PageRange $range): string
    {
        $lines = explode("\n", $anchoredText);
        $kept = [];
        $inRange = false;
        foreach ($lines as $line) {
            if (preg_match('/^\[page:([0-9]+)\]$/', $line, $m) === 1) {
                $page = (int) $m[1];
                $inRange = $page >= $range->start && $page < $range->end;
            }
            if ($inRange) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }

    private function redactAuthScheme(AuthHeaderScheme $scheme): string
    {
        return match ($scheme) {
            AuthHeaderScheme::Bearer => 'Authorization: Bearer ***',
            AuthHeaderScheme::XGoogApiKey => 'x-goog-api-key: ***',
            AuthHeaderScheme::XApiKey => 'x-api-key: ***',
        };
    }

    private function optionString(string $key, string $default): string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function optionNullableString(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
