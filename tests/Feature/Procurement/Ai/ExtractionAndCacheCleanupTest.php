<?php

declare(strict_types=1);

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\StructuredTask;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Ai\AnalysisRecipe;
use App\Dto\Procurement\Ai\PageDiscoveryOutput;
use App\Dto\Procurement\Ai\PageRange;
use App\Dto\Procurement\Ai\RowRange;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\OriginBudgetPolicy;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Dto\Procurement\Process\ExtractionWindowInput;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\PublicationInput;
use App\Dto\Procurement\Process\ScreeningInput;
use App\Dto\Procurement\Process\WorkId;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\StructuredDisposition;
use App\Enums\Llm\TaskPurpose;
use App\Models\Llm\Prompt;
use App\Models\Llm\Provider;
use App\Repositories\Procurement\Ai\AnalysisPlanRepository;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\PromptCanonReader;
use App\Services\Llm\PromptCanonSynchronizer;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Llm\Schema\ProviderSchemaCompiler;
use App\Services\Llm\Validation\StructuredTaskValidator;
use App\Services\Procurement\Extraction\AnalysisGeneration;
use App\Services\Procurement\Extraction\AnalysisProviderConnections;
use App\Services\Procurement\Extraction\AnalysisProviderSendGate;
use App\Services\Procurement\Extraction\AnalysisRecipeFactory;
use App\Services\Procurement\Extraction\DiscoverySemanticValidator;
use App\Services\Procurement\Extraction\ExtractionContract;
use App\Services\Procurement\Extraction\ExtractionHandler;
use App\Services\Procurement\Extraction\PublicationHandler;
use App\Services\Procurement\Extraction\WindowExtractionTask;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ExecutionContextFactory;
use App\Services\Procurement\Process\WorkFinalizer;
use App\Services\Procurement\Screening\ScreeningHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\ProcessCore\AiFixtures;
use Tests\Support\ProcessCore\ExtractionRuntimeFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\TransportFixtures;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\PageRuntimeFixtures;
use Tests\Support\ProcessCore\ScreeningRuntimeFixtures;

test('арифметика принимает документированный коэффициент в пределах настроенного допуска', function (): void {
    config(['ai.extraction.coefficient_tolerance' => '0.02']);
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $payload = json_decode(AiFixtures::raw(), associative: true, flags: JSON_THROW_ON_ERROR);
    $payload['rows'][0]['row'] = [
        ...$payload['rows'][0]['row'], 'quantity' => '2', 'unit_price_curr' => '100', 'total_price_curr' => '230.02',
        'attributes' => ['modifiers' => [['name' => 'К=1.15', 'kind' => 'price', 'value' => '1.15']]],
    ];
    $validator = app(StructuredTaskValidator::class);
    $validate = fn (array $input) => $validator->validate($task, CanonicalJson::encode($input), 'STOP');
    expect($validate($payload)->disposition)->toBe(StructuredDisposition::Accepted);

    $payload['rows'][0]['row']['total_price_curr'] = '230.03';
    expect($validate($payload)->errors)->toContain('totals_arithmetic');
    unset($payload['rows'][0]['row']['attributes']);
    $payload['rows'][0]['row']['total_price_curr'] = '230.00';
    expect($validate($payload)->disposition)->toBe(StructuredDisposition::InvalidSemantics);
});

test('явный итог по позициям сверяется с полным набором цен с учётом допуска округления', function (): void {
    config(['ai.extraction.rounding_tolerance' => '0.01']);
    $task = (new ExtractionContract)->task(new RowRange(0, 2), 1, ['p0:r0' => 0, 'p0:r1' => 0]);
    $payload = json_decode(AiFixtures::raw(), associative: true, flags: JSON_THROW_ON_ERROR);
    $payload['rows'][0]['row']['unit_price_curr'] = $payload['rows'][0]['row']['total_price_curr'] = '10.00';
    $payload['rows'][1]['row']['total_price_curr'] = '20.00';
    $payload['totals'][0]['label'] = 'Итого по позициям';
    $payload['totals'][0]['value'] = '30.01';
    $validator = app(StructuredTaskValidator::class);
    expect($validator->validate($task, CanonicalJson::encode($payload), 'STOP')->disposition)->toBe(StructuredDisposition::Accepted);

    $payload['totals'][0]['value'] = '30.02';
    expect($validator->validate($task, CanonicalJson::encode($payload), 'STOP')->errors)->toContain('totals_arithmetic');
    $payload['totals'][0]['label'] = 'Всего с НДС';
    expect($validator->validate($task, CanonicalJson::encode($payload), 'STOP')->disposition)->toBe(StructuredDisposition::Accepted);
});

test('превышение размера ответа требует разбиения до декодирования JSON', function (): void {
    $task = (new ExtractionContract)->task(new RowRange(0, 1), 1, ['p0:r0' => 0]);
    $validator = app(StructuredTaskValidator::class);

    expect($validator->validate($task, str_repeat('x', 1_048_577), 'STOP')->disposition)
        ->toBe(StructuredDisposition::SplitRequired)
        ->and($validator->validate($task, str_repeat('x', 1_048_576), 'STOP')->disposition)
        ->toBe(StructuredDisposition::InvalidSchema);
});

test('настройки арифметики зафиксированы в рецепте и переживают изменение конфигурации', function (): void {
    $legacy = AiFixtures::recipe();
    config(['ai.extraction.rounding_tolerance' => '0.02']);
    $factory = new AnalysisRecipeFactory;
    $recipe = $factory->make($legacy->preparationHash, $legacy->llm, $legacy->coverage);
    config(['ai.extraction.rounding_tolerance' => '0.09']);

    $restored = AnalysisRecipe::restore($recipe->json, AnalysisRecipe::CODEC_VERSION);
    expect($restored->arithmetic?->roundingTolerance)->toBe('0.02')
        ->and($restored->hash)->toEqual($recipe->hash)
        ->and($factory->make($legacy->preparationHash, $legacy->llm, $legacy->coverage)->hash)->not->toEqual($recipe->hash)
        ->and(AnalysisRecipe::restore($legacy->json, AnalysisRecipe::CODEC_VERSION)->hash)->toEqual($legacy->hash);
});

test('конфликт защищённой версии откатывает весь импорт канона промтов', function (): void {
    $records = app(PromptCanonReader::class)->read();
    $first = $records->first();
    $conflict = $records->last();
    $protected = Prompt::factory()->create([...$conflict, 'content' => 'Ранее закреплённый текст', 'is_protected' => true]);

    expect($first['key'])->not->toBe($conflict['key'])
        ->and(fn () => app(PromptCanonSynchronizer::class)->sync())->toThrow(RuntimeException::class, 'Увеличьте version')
        ->and(Prompt::query()->count())->toBe(1)
        ->and($protected->refresh()->content)->toBe('Ранее закреплённый текст');
});

test('после разбиения обнаружение отправляет только страницы выбранного диапазона', function (): void {
    $llm = new PinnedLlmContext('fixture-provider', 'fixture-account', 'v1beta', 'fixture-model', 'route/1',
        ['max_output_tokens' => 4096], ['include_thoughts' => false]);
    [$work, $lease, $run, $period] = PageRuntimeFixtures::work($llm);
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $finalizer = app(WorkFinalizer::class);
    $handler = app(ScreeningHandler::class);
    $contexts = app(ExecutionContextFactory::class);
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    $finalizer->finish($lease, $handler->execute($input, $contexts->open($lease)));
    $lease = FinalizeFixtures::running($work);
    PageRuntimeFixtures::capture($lease, $run, $period, '{"pages":[]}', new PageRange(0, 4), 'MAX_TOKENS');
    $finalizer->finish($lease, $handler->execute($input, $contexts->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $checkpoint = $lease->checkpoint->stageProgress->discovery;
    expect($checkpoint->pending)->toEqual([new PageRange(0, 2), new PageRange(2, 4)]);
    $range = $checkpoint->pending[0];

    $origin = CanonicalOrigin::fromUrl('https://fixture.test');
    $profile = TransportFixtures::profile(['total_seconds' => 4]);
    $pricing = FinancialFixtures::pricing();
    app(AnalysisProviderConnections::class)->register(new AnalysisProviderConnection(
        $run->recipe->llm, $origin->value(), $pricing, $period, $profile, 'fixture-only-key',
    ));
    app(SourceCatalogSync::class)->syncProvider(new ProviderTargetDefinitions(new ProviderKey($run->recipe->llm->provider), [
        new SourceTargetDefinition('provider:fixture-provider:generate', 'provider', $origin, [$profile], new OriginBudgetPolicy(600, 1, 2)),
    ]));
    app()->bind(AnalysisProviderSendGate::class, static fn () => throw new RuntimeException('Остановка перед сетевой отправкой'));
    $snapshot = $run->recipe->tasks->task(TaskPurpose::Discovery);
    $task = new StructuredTask(PageDiscoveryOutput::class, $snapshot->prompt, TaskPurpose::Discovery,
        new DiscoverySemanticValidator($range, $checkpoint->assignments));
    expect(fn () => app(AnalysisGeneration::class)->discoveryReceipt($run, $range, 0, $task, $contexts->open($lease)))
        ->toThrow(RuntimeException::class, 'Остановка перед сетевой отправкой');
    $expected = new ProviderOperationPlan(
        identity: new RequestIdentity($run->recipe->hash->hex, $run->discoveryInputHash($range)->hex, OperationPurpose::Generate, 0, $run->id->value),
        context: $run->recipe->llm,
        pricing: $pricing,
        consumerCorrelationId: $work->value->value,
        generation: new ProviderGenerationInput(
            system: $snapshot->prompt,
            user: CanonicalJson::encode(['range' => $range->fields(), 'semantic_reask_ordinal' => 0,
                'untrusted_document' => "[page:0]\n[page:0:line:0;row_index:0] Смета1 строка1\n[page:0:line:1;row_index:1] Смета1 строка2\n[page:1]\n[page:1:line:0;row_index:100000] Смета1 строка3\n"]),
            schema: (new ProviderSchemaCompiler)->compile($snapshot->schema),
            purpose: TaskPurpose::Discovery,
        ),
    );
    $wire = app(StructuredCallExecutor::class)->compileGeneration($expected, $origin->value());
    expect(DB::table('llm_provider_calls')->whereNull('send_claimed_at')->selectRaw("encode(wire_fingerprint, 'hex') AS fingerprint")->sole()->fingerprint)
        ->toBe($wire->fingerprint);
    Http::assertNothingSent();
});

test('публикация по страницам сохраняет число извлечённых строк и оставляет rows_expected пустым', function (): void {
    [$work, $lease, $run, $period] = PageRuntimeFixtures::work();
    $input = new ScreeningInput($run->documentVersionId, $run->representationHash, $run->recipe->hash);
    $finalizer = app(WorkFinalizer::class);
    $contexts = app(ExecutionContextFactory::class);
    $screening = app(ScreeningHandler::class);
    PageRuntimeFixtures::capture($lease, $run, $period, ScreeningRuntimeFixtures::raw());
    $finalizer->finish($lease, $screening->execute($input, $contexts->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $discovery = PageDiscoveryOutput::from(['pages' => array_map(
        static fn (int $page): array => ['page_index' => $page, 'estimate_ordinal' => $page < 2 ? 1 : 2], range(0, 3),
    ), 'ambiguous_pages' => []]);
    PageRuntimeFixtures::capture($lease, $run, $period, $discovery->toJson(), new PageRange(0, 4));
    $finalizer->finish($lease, $screening->execute($input, $contexts->open($lease)));
    $lease = FinalizeFixtures::running($work);
    $finalizer->finish($lease, $screening->execute($input, $contexts->open($lease)));

    $extraction = app(ExtractionHandler::class);
    foreach (app(AnalysisPlanRepository::class)->plans($run) as $plan) {
        $window = $plan->windows[0];
        $id = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('analysis_window_id', $window->id->value)->soleValue('id')));
        $lease = FinalizeFixtures::running($id);
        $rows = [];
        foreach ($window->sourceAnchors as $anchor => $page) {
            if (preg_match('/^page:[0-9]+:line:([0-9]+)$/D', $anchor, $match) !== 1) {
                continue;
            }
            $line = (int) $match[1];
            $rows[] = ['source_anchor' => $anchor, 'page_index' => $page, 'estimate_ordinal' => $window->estimateOrdinal,
                'row' => ['row_type' => 'item', 'row_index' => $page * 100000 + $line, 'name' => 'Позиция']];
        }
        $payload = CanonicalJson::encode(['rows' => $rows, 'totals' => [], 'covered_pages' => range($plan->required->start, $plan->required->end - 1)]);
        $validated = app(StructuredTaskValidator::class)->validate(WindowExtractionTask::for($window, $window->range), $payload, 'STOP');
        expect($validated->errors)->toBe([]);
        ExtractionRuntimeFixtures::capture($lease, $window, $period, $payload);
        $windowInput = new ExtractionWindowInput($run->id, $window->windowKey, $run->recipe->hash);
        $progress = $extraction->execute($windowInput, $contexts->open($lease));
        expect($progress->checkpoint->blocked)->toBeNull()
            ->and($finalizer->finish($lease, $progress)->state?->value)->toBe('ready');
        $lease = FinalizeFixtures::running($id);
        expect($finalizer->finish($lease, $extraction->execute($windowInput, $contexts->open($lease)))->state?->value)->toBe('succeeded');
    }
    $id = new WorkId(new ProcessUuid(DB::table('prc_process_work')->where('stage', 'publication')->soleValue('id')));
    $lease = FinalizeFixtures::running($id);
    $publication = new PublicationInput($run->id, $run->recipe->hash, $run->generation);
    expect($finalizer->finish($lease, app(PublicationHandler::class)->execute($publication, $contexts->open($lease)))->state?->value)->toBe('succeeded');
    expect(DB::table('prc_estimates')->orderBy('ordinal')->pluck('rows_expected')->all())->toBe([null, null])
        ->and(DB::table('prc_estimates')->orderBy('ordinal')->pluck('rows_extracted')->all())->toBe([3, 2]);
});

test('очистка удаляет только истёкшие локальные ассеты с завершёнными операциями', function (): void {
    $period = FinancialFixtures::reset();
    $lookup = FinancialFixtures::genericPlan('pending-asset-lookup', OperationPurpose::Lookup);
    DB::transaction(fn () => app(RequestLedger::class)->authorize($lookup, LedgerFixtures::uuid()->value, 1, $period, ProviderOperationIds::allocate()));
    $provider = Provider::query()->where('code', $lookup->context->provider)->sole();
    $kept = [];
    foreach ([
        'expired' => ['ready-assets', now()->subDay()],
        'active' => ['ready-assets', now()->addDay()],
        'creating' => ['ready-assets', now()->subDay()],
        'lookup' => [$lookup->context->account, now()->subDay()],
    ] as $case => [$account, $expiry]) {
        $id = LedgerFixtures::uuid()->value;
        $hash = DB::raw("decode('".hash('sha256', $id)."','hex')");
        $asset = ['id' => $id, 'provider_id' => $provider->id, 'account_id' => $account,
            'api_surface' => $lookup->context->apiVersion, 'expires_at' => $expiry];
        DB::table('llm_file_assets')->insert([...$asset, 'content_hash' => $hash, 'mime' => 'application/pdf',
            'provider_uri' => "https://fixture.test/files/{$id}", 'state' => $case === 'creating' ? 'creating' : 'ACTIVE']);
        DB::table('llm_context_caches')->insert([...$asset, 'model' => $lookup->context->model, 'system_hash' => $hash,
            'schema_tool_policy_hash' => $hash, 'representation_hash' => $hash, 'content_ordering_hash' => $hash,
            'provider_name' => "cachedContents/{$id}", 'creation_state' => $case === 'creating' ? 'creating' : 'active', 'cost' => '0.01']);
        if ($case !== 'expired') {
            $kept[] = $id;
        }
    }

    $this->artisan('ai:caches', ['action' => 'prune'])->expectsOutputToContain('файлов 1, кэшей 1')->assertSuccessful();
    $this->artisan('ai:caches', ['action' => 'prune'])->expectsOutputToContain('файлов 0, кэшей 0')->assertSuccessful();
    expect(DB::table('llm_file_assets')->pluck('id')->all())->toEqualCanonicalizing($kept)
        ->and(DB::table('llm_context_caches')->pluck('id')->all())->toEqualCanonicalizing($kept)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)
        ->and(DB::table('llm_budget_reservations')->soleValue('state'))->toBe('reserved');
    Http::assertNothingSent();
});
