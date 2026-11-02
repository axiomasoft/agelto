<?php

declare(strict_types=1);

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderCapabilities;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\ProviderOperationIds;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Procurement\Process\ProviderRecoveryPlan;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Process\ProviderRecoveryMode;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Cache\AssetRepository;
use App\Repositories\Llm\Request\RequestRepository;
use App\Services\Llm\Cache\ProviderAssetManager;
use App\Services\Llm\Execution\ProviderGenerationInput;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ReceiptPolicies;
use App\Services\Llm\Execution\ReceiptStore;
use App\Services\Llm\Execution\RequestLedger;
use App\Services\Llm\Execution\StructuredCallExecutor;
use App\Services\Llm\Providers\GeminiAssetClient;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\Providers\GeminiGenerateContentClient;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Process\RecoveryReconciler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\Postgres\ConcurrentTransactions;
use Tests\Support\ProcessCore\AssetFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;
use Tests\Support\ProcessCore\FinancialFixtures;
use Tests\Support\ProcessCore\Http\WireFixture;
use Tests\Support\ProcessCore\LedgerFixtures;
use Tests\Support\ProcessCore\ProviderSendFixtures;

pest()->group('p312', 't14', 't22', 't30');

beforeEach(function (): void {
    $this->server = new WireFixture;
});

afterEach(function (): void {
    $this->server->stop();
});

function p312AssetFixture(WireFixture $server, OperationPurpose $purpose): ProviderSendFixtures
{
    $f = new ProviderSendFixtures($server, operationFactory: function (ProviderOperationPlan $base, string $origin) use ($purpose): array {
        $client = new GeminiAssetClient($origin);
        $caps = AssetFixtures::capabilities($base->context);
        if ($purpose === OperationPurpose::FileUpload) {
            $key = AssetFixtures::file($base->context)->key;
            $plan = AssetFixtures::plan($base, $purpose, $key->hash);

            return [$plan, $client->upload($plan, $caps, $key, AssetFixtures::BYTES)];
        }
        $prefix = AssetFixtures::prefix($base);
        $plan = AssetFixtures::plan($base, $purpose, $prefix->key->hash);

        return [$plan, $client->createCache($plan, $caps, $prefix, 2048, 10, 1000, 3600)];
    });
    app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);
    $server->respond(parse_url($f->wire->endpoint, PHP_URL_PATH), json_encode(AssetFixtures::envelope($purpose === OperationPurpose::FileUpload ? 'file' : 'cache'), JSON_THROW_ON_ERROR),
        headers: ['Content-Type' => 'application/json'], method: 'POST');

    return $f;
}

function p312CachedFixture(WireFixture $server): ProviderSendFixtures
{
    $f = new ProviderSendFixtures($server, operationFactory: function (ProviderOperationPlan $base, string $origin): array {
        $prefix = AssetFixtures::prefix($base);
        $cache = new ContextCacheAsset($prefix->key, 'cachedContents/p312-prefix', gmdate('c', time() + 3600));
        $input = new ProviderGenerationInput($base->generation->system, $base->generation->user, $base->generation->schema,
            $prefix->files, $cache, $prefix, AssetFixtures::capabilities($base->context));
        $plan = new ProviderOperationPlan($base->identity, $base->context, $base->pricing, $base->consumerCorrelationId, generation: $input);

        return [$plan, (new GeminiGenerateContentClient($origin))->compile($plan)];
    });
    app(ReceiptPolicies::class)->register('fixture-provider', new AssetFixtures);

    return $f;
}

test('файл загружается настоящим multipart HTTP с сохранёнными bytes MIME TTL и отдельным нулевым учётом', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::FileUpload);
    $out = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $request = $this->server->receipts()[0];
    $request['headers'] = array_change_key_case($request['headers']);
    $file = app(AssetRepository::class)->findFile($f->wire->assetKey);
    expect($out->status)->toBe(200)->and($request['body_sha256'])->toBe($f->wire->bodyHash)
        ->and($request['headers']['x-goog-upload-protocol'])->toBe('multipart')
        ->and($request['headers']['content-type'])->toBe($f->wire->contentType)
        ->and($f->wire->body)->toContain(AssetFixtures::BYTES)->toContain('Content-Type: application/pdf')
        ->and($file)->toBeInstanceOf(ProviderFileAsset::class)->and($file->usable())->toBeTrue()
        ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000')
        ->and(DB::table('llm_provider_calls')->sole()->usage_schema_version)->toBe(1)
        ->and(DB::table('prc_request_attempts')->sole()->request_id)->toBe($f->operation->physicalCallId);
    expect(fn () => (new GeminiAssetClient)->upload($f->plan, AssetFixtures::capabilities($f->plan->context), $file->key, 'changed bytes'))
        ->toThrow(OperationDenied::class);
});

test('кэш создаётся одним HTTP с полным TTL reserve и точным system file prefix без tools', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::CacheCreate);
    expect(DB::table('llm_budget_reservations')->sole()->upper_bound)->toBe('0.00304800');
    $out = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $body = json_decode($f->wire->body, true, flags: JSON_THROW_ON_ERROR);
    expect($out->status)->toBe(200)->and($body['ttl'])->toBe('3600s')->and($body['model'])->toBe('models/fixture-model')
        ->and($body['contents'][0]['parts'][0]['fileData']['mimeType'])->toBe('application/pdf')
        ->and($body)->not->toHaveKey('tools')->and($this->server->receipts())->toHaveCount(1)
        ->and(app(AssetRepository::class)->findCache($f->wire->assetKey)->usable())->toBeTrue()
        ->and(DB::table('llm_context_caches')->sole()->cost)->toBe('0.00304800');
});

test('cached wire исключает system и file prefix; uncached сохраняет полную schema и стабильные части', function (): void {
    $f = p312CachedFixture($this->server);
    $cached = json_decode($f->wire->body, true, flags: JSON_THROW_ON_ERROR);
    $input = $f->plan->generation;
    $plain = new ProviderGenerationInput($input->system, $input->user, $input->schema, $input->files, capabilities: $input->capabilities);
    $plan = new ProviderOperationPlan($f->plan->identity, $f->plan->context, $f->plan->pricing, $f->plan->consumerCorrelationId, generation: $plain);
    $wire = (new GeminiGenerateContentClient($this->server->url('/')->origin->value()))->compile($plan);
    $uncached = json_decode($wire->body, true, flags: JSON_THROW_ON_ERROR);
    expect($cached)->not->toHaveKey('systemInstruction')->and($cached['cachedContent'])->toBe('cachedContents/p312-prefix')
        ->and($cached['contents'][0]['parts'])->toBe([['text' => $input->user]])
        ->and($uncached)->not->toHaveKey('cachedContent')->and($uncached['systemInstruction']['parts'][0]['text'])->toBe($input->system->content)
        ->and($uncached['contents'][0]['parts'][0]['fileData']['fileUri'])->toBe($input->files[0]->uri)
        ->and($uncached['generationConfig'])->toBe($cached['generationConfig'])->and($this->server->receipts())->toBe([]);
});

test('capability identity account API model surface и expiry не переносятся между маршрутами', function (): void {
    $f = p312CachedFixture($this->server);
    $original = $f->plan->context;
    foreach (['provider', 'account', 'apiVersion', 'model'] as $field) {
        $values = [$original->provider, $original->account, $original->apiVersion, $original->model];
        $values[array_search($field, ['provider', 'account', 'apiVersion', 'model'], true)] .= '-other';
        $other = new PinnedLlmContext(...[...$values, $original->routePolicyVersion, $original->options, $original->thinking]);
        expect(fn () => AssetFixtures::capabilities($original)->assertContext($other))->toThrow(OperationDenied::class);
    }
    expect(fn () => (new ProviderCapabilities($original, 'interactions', '1', '2027-01-01', true, true, true, 1, 1024, 60, 'explicit'))->assertContext($original))
        ->toThrow(OperationDenied::class);
    expect(fn () => (new ProviderCapabilities($original, 'generateContent', '1', '2020-01-01', true, true, true, 1, 1024, 60, 'obsolete'))->assertContext($original))
        ->toThrow(OperationDenied::class);
    Http::assertNothingSent();
});

test('unknown reuse unsupported explicit minimum tokens и невыгодная цена не создают кэш; TTL не расширяется автоматически', function (): void {
    $f = p312CachedFixture($this->server);
    $prefix = $f->plan->generation->prefix;
    $plan = AssetFixtures::plan($f->plan, OperationPurpose::CacheCreate, $prefix->key->hash);
    $client = new GeminiAssetClient;
    foreach ([[2048, null, 1000], [1024, 10, 1000], [2048, 1, 1]] as [$tokens, $reuse, $saved]) {
        expect($client->createCache($plan, AssetFixtures::capabilities($plan->context), $prefix, $tokens, $reuse, $saved, 3600))->toBeNull();
    }
    expect($client->createCache($plan, AssetFixtures::capabilities($plan->context, false), $prefix, 2048, 10, 1000, 3600))->toBeNull()
        ->and(fn () => $client->createCache($plan, AssetFixtures::capabilities($plan->context), $prefix, 2048, 10, 1000, 7200))->toThrow(OperationDenied::class, 'cache_ttl_not_reserved')
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)->and($this->server->receipts())->toBe([]);
});

test('известный бесплатный cache404 допускает ровно один uncached physical retry того же logical key с новым reserve', function (bool $secondMiss): void {
    $f = p312CachedFixture($this->server);
    $path = parse_url($f->wire->endpoint, PHP_URL_PATH);
    $this->server->respond($path, '{"error":{"code":404},"proof":"p312-cache-miss-proof/1"}', 404, method: 'POST', replace: true);
    $this->server->respond($path, $secondMiss ? '{"proof":"p312-cache-miss-proof/1","error":{"code":404}}' : FinancialFixtures::body(), $secondMiss ? 404 : 200, method: 'POST');
    $result = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    expect($result->denial->value ?? null)->toBeNull()->and($result->failure->value ?? null)->toBeNull();
    expect($result->status)->toBe(404)->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000');
    expect(fn () => app(AnalysisCallCoordinator::class)->authorize($f->lease, $f->run, $f->plan, $f->period, $f->wire))
        ->toThrow(OperationDenied::class, 'cache_miss_requires_uncached_wire');
    FinalizeFixtures::expire($f->work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $f->lease = FinalizeFixtures::running($f->work);
    expect($f->lease->checkpoint->providerRecoveryPlan->mode)->toBe(ProviderRecoveryMode::KnownRetryEligible);
    $input = $f->plan->generation;
    $plan = new ProviderOperationPlan($f->plan->identity, $f->plan->context, $f->plan->pricing, $f->plan->consumerCorrelationId,
        generation: new ProviderGenerationInput($input->system, $input->user, $input->schema, $input->files, capabilities: $input->capabilities));
    $wire = (new GeminiGenerateContentClient($this->server->url('/')->origin->value()))->compile($plan);
    $next = app(AnalysisCallCoordinator::class)->authorize($f->lease, $f->run, $plan, $f->period, $wire);
    expect($next->logicalRequestId)->toBe($f->operation->logicalRequestId)->and($next->physicalCallId)->not->toBe($f->operation->physicalCallId)
        ->and(DB::table('llm_budget_reservations')->count())->toBe(2);
    expect(app(StructuredCallExecutor::class)->execute($next, $wire, $f->gate())->status)->toBe($secondMiss ? 404 : 200)
        ->and($this->server->receipts())->toHaveCount(2)
        ->and(fn () => app(AnalysisCallCoordinator::class)->authorize($f->lease, $f->run, $plan, $f->period, $wire))->toThrow(OperationDenied::class)
        ->and(DB::table('llm_provider_requests')->count())->toBe(1);
})->with(['успех uncached' => false, 'повторный miss не даёт третьего HTTP' => true]);

test('cache404 без доказательства оплаты остаётся unknown и не посылает fallback', function (): void {
    $f = p312CachedFixture($this->server);
    $path = parse_url($f->wire->endpoint, PHP_URL_PATH);
    $this->server->respond($path, '{"error":{"code":404,"status":"NOT_FOUND"}}', 404, method: 'POST', replace: true);
    $result = app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    expect($result->denial->value ?? null)->toBeNull()->and($result->failure->value ?? null)->toBeNull();
    expect($result->status)->toBe(404)
        ->and(app(RequestRepository::class)->get($f->operation->physicalCallId)->requestState)->toBe('unknown')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved')
        ->and(fn () => app(AnalysisCallCoordinator::class)->authorize($f->lease, $f->run, $f->plan, $f->period, $f->wire))->toThrow(OperationDenied::class)
        ->and($this->server->receipts())->toHaveCount(1);
});

test('cache/file put-before-ref восстанавливает asset purpose и metadata после blocked без generate parsing и нового send', function (OperationPurpose $purpose): void {
    $f = p312AssetFixture($this->server, $purpose);
    $manager = app(ProviderAssetManager::class);
    $manager->claim($f->wire->assetKey, $f->operation->physicalCallId, $f->wire);
    $out = $f->gate()->claimSend($f->operation, $f->wire)->transport->sendOnce($f->wire);
    $call = app(RequestRepository::class)->get($f->operation->physicalCallId);
    app(ReceiptStore::class)->put($call, $out->status, $out->body());
    FinalizeFixtures::expire($f->work, 600);
    expect(app(RecoveryReconciler::class)->recoverExpiredRunning())->toBe(1);
    $lease = FinalizeFixtures::running($f->work);
    expect($lease->checkpoint->providerRecoveryPlan)->toBeInstanceOf(ProviderRecoveryPlan::class)
        ->and($lease->checkpoint->providerRecoveryPlan->mode)->toBe(ProviderRecoveryMode::ResumeProviderAsset);
    $receipt = app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($f->operation->physicalCallId));
    $asset = app(ProviderAssetManager::class)->restore($f->wire->assetKey, $receipt);
    expect($asset->usable())->toBeTrue()->and(DB::table('prc_analysis_chunks')->count())->toBe(0)
        ->and(DB::table('llm_provider_calls')->count())->toBe(1)->and(DB::table('llm_budget_reservations')->count())->toBe(1)
        ->and($this->server->receipts())->toHaveCount(1);
})->with([OperationPurpose::FileUpload, OperationPurpose::CacheCreate]);

test('неопределённый remote create сохраняет reserve и блокирует второго создателя даже после lease и TTL', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::CacheCreate);
    app(ProviderAssetManager::class)->claim($f->wire->assetKey, $f->operation->physicalCallId, $f->wire);
    expect($f->gate()->claimSend($f->operation, $f->wire)->transport)->not->toBeNull();
    DB::transaction(fn () => app(RequestRepository::class)->interruptLocked(app(RequestRepository::class)->get($f->operation->physicalCallId)));
    DB::table('llm_context_caches')->update(['creation_lease_until' => DB::raw("clock_timestamp()-interval '1 hour'"), 'expires_at' => DB::raw("clock_timestamp()-interval '1 hour'")]);
    $generation = FinancialFixtures::genericPlan('after-unknown');
    expect(fn () => DB::transaction(fn () => app(RequestLedger::class)->authorize($generation, LedgerFixtures::uuid()->value, 1, $f->period, ProviderOperationIds::allocate())))
        ->toThrow(OperationDenied::class, 'account_unknown_hold')
        ->and(DB::table('llm_budget_reservations')->sole()->state)->toBe('reserved')->and($this->server->receipts())->toBe([]);
});

test('два настоящих PostgreSQL процесса выбирают одного создателя одного prefix', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::CacheCreate);
    $key = $f->wire->assetKey;
    $other = new ProviderOperationPlan(new RequestIdentity($f->plan->identity->recipeHash, hash('sha256', 'second-creator'), OperationPurpose::CacheCreate, 0, $f->run->value),
        $f->plan->context, $f->plan->pricing, 'second-creator');
    $op = DB::transaction(fn () => app(RequestLedger::class)->authorize($other, LedgerFixtures::uuid()->value, 1, $f->period, ProviderOperationIds::allocate()));
    $make = static function (string $physical) use ($key): string {
        $encoded = base64_encode(serialize($key));

        return '$key=unserialize(base64_decode('.var_export($encoded, true).'));'
            .'try { \\Illuminate\\Support\\Facades\\DB::transaction(fn()=>app(\\App\\Repositories\\Llm\\Cache\\AssetRepository::class)->claim($key,app(\\App\\Repositories\\Llm\\Request\\RequestRepository::class)->get('.var_export($physical, true).'))); $won=true; } catch (\\App\\Exceptions\\Llm\\OperationDenied) { $won=false; }'
            .'return ["won"=>$won,"pid"=>\\Illuminate\\Support\\Facades\\DB::selectOne("SELECT pg_backend_pid() AS pid")->pid];';
    };
    $results = ConcurrentTransactions::concurrently(['first' => $make($f->operation->physicalCallId), 'second' => $make($op->physicalCallId)]);
    expect($results['first']['pid'])->not->toBe($results['second']['pid'])
        ->and((int) $results['first']['won'] + (int) $results['second']['won'])->toBe(1)
        ->and(DB::table('llm_context_caches')->count())->toBe(1)->and($this->server->receipts())->toBe([]);
});

test('явный refresh имеет новый request и полный TTL reserve; lookup не перезаписывает стоимость создания', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::CacheCreate);
    app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $key = $f->wire->assetKey;
    $cache = app(AssetRepository::class)->findCache($key);
    $original = $f->operation->logicalRequestId;
    $client = new GeminiAssetClient($this->server->url('/')->origin->value());
    $plan = AssetFixtures::plan($f->plan, OperationPurpose::CacheRefresh, $key->hash);
    $wire = $client->refreshCache($plan, AssetFixtures::capabilities($plan->context), $cache, 2048, 3600);
    AssetFixtures::advance($f, $plan, $wire);
    $this->server->respond('/v1beta/'.$cache->name, json_encode(AssetFixtures::envelope('cache'), JSON_THROW_ON_ERROR), method: 'PATCH');
    expect($f->operation->logicalRequestId)->not->toBe($original)
        ->and(app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate())->status)->toBe(200)
        ->and(DB::table('llm_context_caches')->sole()->cost)->toBe('0.00509600')
        ->and($this->server->receipts()[1]['path'])->toBe('/v1beta/'.$cache->name.'?updateMask=ttl');
    $receipt = app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($f->operation->physicalCallId));
    app(ProviderAssetManager::class)->restore($key, $receipt);
    expect(DB::table('llm_context_caches')->sole()->cost)->toBe('0.00509600');
    $plan = AssetFixtures::plan($plan, OperationPurpose::Lookup, $key->hash);
    $wire = $client->lookup($plan, AssetFixtures::capabilities($plan->context), $key, $cache->name);
    AssetFixtures::advance($f, $plan, $wire);
    $metadata = AssetFixtures::envelope('cache');
    $metadata['expireTime'] = app(AssetRepository::class)->findCache($key)->expiresAt;
    $this->server->respond('/v1beta/'.$cache->name, json_encode($metadata, JSON_THROW_ON_ERROR));
    expect(app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate())->status)->toBe(200)
        ->and(DB::table('llm_context_caches')->sole()->cost)->toBe('0.00509600')
        ->and(DB::table('llm_budget_reservations')->count())->toBe(3)
        ->and(json_decode(DB::table('llm_provider_calls')->where('physical_call_id', $f->operation->physicalCallId)->value('usage'), true)['cache_storage_token_hours'])->toBe(0)
        ->and($this->server->receipts())->toHaveCount(3)->and($this->server->receipts()[2]['method'])->toBe('GET');
});

test('доказанный file404 даёт один reupload тех же immutable bytes; повтор delivery не загружает снова', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::FileUpload);
    app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $key = $f->wire->assetKey;
    $client = new GeminiAssetClient($this->server->url('/')->origin->value());
    $plan = AssetFixtures::plan($f->plan, OperationPurpose::Lookup, $key->hash);
    $wire = $client->lookup($plan, AssetFixtures::capabilities($plan->context), $key, 'files/p312-upload');
    AssetFixtures::advance($f, $plan, $wire);
    $this->server->respond('/v1beta/files/p312-upload', '{"proof":"p312-file-missing-proof/1","error":{"code":404}}', 404);
    expect(app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate())->status)->toBe(404);
    $receipt = app(ReceiptStore::class)->inspect(app(RequestRepository::class)->get($f->operation->physicalCallId));
    app(ProviderAssetManager::class)->invalidateMissing($key, $wire, $receipt);
    expect(app(AssetRepository::class)->findFile($key))->toBeNull();
    // The same frozen run has one replacement identity even if the remote file disappears again.
    $inputHash = $key->replacementInputHash();
    $plan = AssetFixtures::plan($plan, OperationPurpose::FileUpload, $inputHash);
    $wire = $client->upload($plan, AssetFixtures::capabilities($plan->context), $key, AssetFixtures::BYTES);
    AssetFixtures::advance($f, $plan, $wire);
    expect(app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate())->status)->toBe(200)
        ->and($this->server->receipts())->toHaveCount(3)
        ->and($this->server->receipts()[0]['body_sha256'])->toBe($this->server->receipts()[2]['body_sha256'])
        ->and(app(StructuredCallExecutor::class)->execute($f->operation, $wire, $f->gate())->denial)->not->toBeNull()
        ->and($this->server->receipts())->toHaveCount(3);
});

test('любое измерение identity cache исключает reuse', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::CacheCreate);
    app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $key = $f->wire->assetKey;
    foreach (['systemHash', 'schemaToolPolicyHash', 'representationHash', 'contentOrderingHash'] as $field) {
        $fields = [$key->systemHash, $key->schemaToolPolicyHash, $key->representationHash, $key->contentOrderingHash];
        $fields[array_search($field, ['systemHash', 'schemaToolPolicyHash', 'representationHash', 'contentOrderingHash'], true)] = hash('sha256', 'other');
        expect(app(AssetRepository::class)->findCache(new ContextCacheKey($key->context, ...$fields)))->toBeNull();
    }
    $base = $f->plan->context;
    foreach (['provider', 'account', 'apiVersion', 'model'] as $i => $field) {
        $values = [$base->provider, $base->account, $base->apiVersion, $base->model];
        $values[$i] .= '-other';
        $context = new PinnedLlmContext(...[...$values, $base->routePolicyVersion, $base->options, $base->thinking]);
        if ($field === 'provider') {
            expect(fn () => app(AssetRepository::class)->findCache(new ContextCacheKey($context, $key->systemHash, $key->schemaToolPolicyHash, $key->representationHash, $key->contentOrderingHash)))
                ->toThrow(OperationDenied::class);
        } else {
            expect(app(AssetRepository::class)->findCache(new ContextCacheKey($context, $key->systemHash, $key->schemaToolPolicyHash, $key->representationHash, $key->contentOrderingHash)))->toBeNull();
        }
    }
});

test('expired cache выбирает plain wire без refresh', function (): void {
    $f = p312CachedFixture($this->server);
    $input = $f->plan->generation;
    $expired = new ContextCacheAsset($input->cache->key, $input->cache->name, '2020-01-01T00:00:00Z');
    $plan = new ProviderOperationPlan($f->plan->identity, $f->plan->context, $f->plan->pricing, $f->plan->consumerCorrelationId,
        generation: new ProviderGenerationInput($input->system, $input->user, $input->schema, $input->files, $expired, $input->prefix, $input->capabilities));
    $wire = (new GeminiGenerateContentClient)->compile($plan);
    expect(json_decode($wire->body, true, flags: JSON_THROW_ON_ERROR))->not->toHaveKey('cachedContent')
        ->and(DB::table('llm_provider_calls')->count())->toBe(1);
});

test('usage разделяет uncached cached output thinking storage и не выдаёт unknown за ноль', function (): void {
    $policy = new GeminiAssetReceiptPolicy;
    $body = json_encode(['candidates' => [['finishReason' => 'STOP']], 'usageMetadata' => [
        'promptTokenCount' => 2000, 'cachedContentTokenCount' => 1500, 'candidatesTokenCount' => 200, 'thoughtsTokenCount' => 300]], JSON_THROW_ON_ERROR);
    $assessment = $policy->assess(OperationPurpose::Generate, 200, $body);
    expect($assessment->usage->uncachedInput)->toBe(500)->and($assessment->usage->cachedInput)->toBe(1500)
        ->and($assessment->usage->output)->toBe(200)->and($assessment->usage->thinking)->toBe(300)
        ->and($assessment->cost)->toBeNull()
        ->and($policy->assess(OperationPurpose::CacheCreate, 404, '{}')->usage->unknown)->toBeTrue()
        ->and($policy->assess(OperationPurpose::CacheRefresh, 200, json_encode(AssetFixtures::envelope('cache'), JSON_THROW_ON_ERROR))->usage->unknown)->toBeTrue()
        ->and($policy->assess(OperationPurpose::CacheCreate, 200, json_encode(AssetFixtures::envelope('cache'), JSON_THROW_ON_ERROR))->usage->cacheStorageTokenHours)->toBe(2048);
});

test('file identity меняется с account API MIME bytes; модель того же account может использовать тот же файл', function (): void {
    $f = p312AssetFixture($this->server, OperationPurpose::FileUpload);
    app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate());
    $key = $f->wire->assetKey;
    expect(app(AssetRepository::class)->findFile(new FileAssetKey($key->context, hash('sha256', 'different bytes'), $key->mime)))->toBeNull()
        ->and(app(AssetRepository::class)->findFile(new FileAssetKey($key->context, $key->contentHash, 'text/plain')))->toBeNull();
    $base = $key->context;
    foreach (['account', 'apiVersion', 'model'] as $field) {
        $context = new PinnedLlmContext($base->provider, $field === 'account' ? 'other-account' : $base->account,
            $field === 'apiVersion' ? 'v1' : $base->apiVersion, $field === 'model' ? 'other-model' : $base->model,
            $base->routePolicyVersion, $base->options, $base->thinking);
        $found = app(AssetRepository::class)->findFile(new FileAssetKey($context, $key->contentHash, $key->mime));
        if ($field === 'model') {
            expect($found->uri)->toBe(app(AssetRepository::class)->findFile($key)->uri);
        } else {
            expect($found)->toBeNull();
        }
    }
});

test('expiry capability во время network wait отзывает authorization до HTTP', function (): void {
    $f = new ProviderSendFixtures($this->server, operationFactory: function (ProviderOperationPlan $base, string $origin): array {
        $prefix = AssetFixtures::prefix($base);
        $cache = new ContextCacheAsset($prefix->key, 'cachedContents/p312-prefix', gmdate('c', time() + 3600));
        $caps = new ProviderCapabilities($base->context, 'generateContent', 'expiring/1', gmdate('c', time() + 2), true, true, true, 2048, 8_388_608, 7200, 'fixture expiry during wait');
        $input = new ProviderGenerationInput($base->generation->system, $base->generation->user, $base->generation->schema, $prefix->files, $cache, $prefix, $caps);
        $plan = new ProviderOperationPlan($base->identity, $base->context, $base->pricing, $base->consumerCorrelationId, generation: $input);

        return [$plan, (new GeminiGenerateContentClient($origin))->compile($plan)];
    });
    DB::table('prc_network_budgets')->update(['not_before' => DB::raw("clock_timestamp()+interval '3 seconds'")]);
    expect(app(StructuredCallExecutor::class)->execute($f->operation, $f->wire, $f->gate())->denial)->not->toBeNull()
        ->and($this->server->receipts())->toBe([])
        ->and(DB::table('llm_provider_calls')->sole()->send_claimed_at)->toBeNull()
        ->and(DB::table('llm_budget_reservations')->sole()->settled_amount)->toBe('0.00000000');
});
