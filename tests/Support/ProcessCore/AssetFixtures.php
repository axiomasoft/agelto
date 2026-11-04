<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Dto\Llm\Cache\CachePrefix;
use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderCapabilities;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Llm\Requests\ReceiptAssessment;
use App\Dto\Llm\Requests\RequestIdentity;
use App\Dto\Llm\Requests\VerifiedReceipt;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Llm\ReceiptOutcome;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Procurement\Ai\AnalysisRequestRepository;
use App\Services\Llm\Execution\AssetRecoveryPolicy;
use App\Services\Llm\Execution\ProviderOperationPlan;
use App\Services\Llm\Execution\ProviderWireRequest;
use App\Services\Llm\Providers\GeminiAssetClient;
use App\Services\Llm\Providers\GeminiAssetReceiptPolicy;
use App\Services\Llm\Providers\GeminiGenerateContentClient;
use App\Services\Procurement\Extraction\AnalysisCallCoordinator;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\Http\WireFixture;

/** Only this synthetic policy accepts the fixture's per-operation nonbilling evidence. */
final readonly class AssetFixtures implements AssetRecoveryPolicy
{
    public const string BYTES = "%PDF-1.7\nimmutable local document\n";

    public static function capabilities(PinnedLlmContext $context, bool $explicit = true): ProviderCapabilities
    {
        return new ProviderCapabilities($context, 'generateContent', 'fixture-capabilities/1', '2027-01-01T00:00:00Z',
            true, $explicit, true, 2048, 8_388_608, 7200, 'Synthetic account/model, official REST shape; no live capability claim.');
    }

    /** @return array<string, mixed> */
    public static function envelope(string $type): array
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/assets-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        $value = $fixture[$type];
        if ($type === 'file') {
            $value['file']['expirationTime'] = gmdate('c', time() + 86400);
        } else {
            $value['createTime'] = gmdate('c');
            $value['expireTime'] = gmdate('c', time() + 3600);
        }

        return $value;
    }

    public static function file(PinnedLlmContext $context): ProviderFileAsset
    {
        $file = self::envelope('file')['file'];

        return new ProviderFileAsset(new FileAssetKey($context, hash('sha256', self::BYTES), 'application/pdf'), $file['uri'], $file['state'], $file['expirationTime']);
    }

    public static function prefix(ProviderOperationPlan $base): CachePrefix
    {
        $input = $base->generation ?? throw new \LogicException('Expected fixture generation.');
        $files = [self::file($base->context)];

        return new CachePrefix(new ContextCacheKey($base->context, $input->system->hash,
            GeminiGenerateContentClient::schemaPolicyHash($input->schema->canonicalHash), hash('sha256', self::BYTES), CachePrefix::orderingHash($files)), $input->system, $files);
    }

    public static function pricing(OperationPurpose $purpose): PricingSnapshot
    {
        return new PricingSnapshot('USD', 'fixture-assets-price/1', $purpose, 0, 4096, 0, true, 0, 0, 0,
            0, $purpose === OperationPurpose::CacheCreate ? 1000 : 0,
            in_array($purpose, [OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh, OperationPurpose::CacheStorage], true) ? 2048 : 0,
            1, 'Synthetic zero-price file/lookup evidence; cache reserves all 2048 token-hours, no savings discounted.', '2027-01-01T00:00:00Z');
    }

    public static function plan(ProviderOperationPlan $base, OperationPurpose $purpose, string $input): ProviderOperationPlan
    {
        return new ProviderOperationPlan(new RequestIdentity($base->identity->recipeHash, $input, $purpose, 0, $base->identity->replayIntentId),
            $base->context, self::pricing($purpose), $base->consumerCorrelationId);
    }

    public static function clearAssets(): void
    {
        DB::statement('TRUNCATE llm_context_caches,llm_file_assets');
    }

    public static function runtime(WireFixture $server, OperationPurpose $purpose): ProviderSendFixtures
    {
        return new ProviderSendFixtures($server, operationFactory: static function (ProviderOperationPlan $base, string $origin) use ($purpose): array {
            $client = new GeminiAssetClient($origin);
            $caps = self::capabilities($base->context);
            if ($purpose === OperationPurpose::FileUpload) {
                $key = self::file($base->context)->key;
                $plan = self::plan($base, $purpose, $key->hash);

                return [$plan, $client->upload($plan, $caps, $key, self::BYTES)];
            }
            $prefix = self::prefix($base);
            $plan = self::plan($base, $purpose, $prefix->key->hash);

            return [$plan, $client->createCache($plan, $caps, $prefix, 2048, 10, 1000, 3600)
                ?? throw new \LogicException('Fixture cache must satisfy its declared eligibility.')];
        }, configureRuntime: static function (): void {
            ScreeningRuntimeFixtures::bind();
            app(ProcessAuthoritySync::class)->registerHandler(StageCode::Screening, ScreeningRuntimeFixtures::VERSION);
        });
    }

    /** Fixture stage handoff only. Real handler checkpoint consumption belongs to . */
    public static function advance(ProviderSendFixtures $fixture, ProviderOperationPlan $plan, ProviderWireRequest $wire): void
    {
        DB::transaction(fn () => app(AnalysisRequestRepository::class)->consumeLocked($fixture->work, $fixture->operation->physicalCallId));
        $fixture->plan = $plan;
        $fixture->wire = $wire;
        $fixture->operation = app(AnalysisCallCoordinator::class)->authorize($fixture->lease, $fixture->run, $plan, $fixture->period, $wire);
    }

    public function assess(OperationPurpose $purpose, int $httpStatus, string $body): ReceiptAssessment
    {
        if ($purpose === OperationPurpose::Lookup && $httpStatus === 404 && str_contains($body, 'p312-file-missing-proof/1')) {
            return new ReceiptAssessment(ReceiptOutcome::NonbillableRetry, '0', 'synthetic-files-contract/1:lookup_not_billed');
        }
        if ($purpose === OperationPurpose::Generate && $httpStatus === 404 && str_contains($body, 'p312-cache-miss-proof/1')) {
            return new ReceiptAssessment(ReceiptOutcome::NonbillableCacheMiss, '0', 'synthetic-provider-contract/1:exact_request_not_billed');
        }
        if ($purpose === OperationPurpose::Generate && $httpStatus === 200) {
            return (new FinancialFixtures)->assess($purpose, $httpStatus, $body);
        }

        return (new GeminiAssetReceiptPolicy)->assess($purpose, $httpStatus, $body);
    }

    public function assetMetadata(FileAssetKey|ContextCacheKey $key, VerifiedReceipt $receipt): ProviderFileAsset|ContextCacheAsset
    {
        return (new GeminiAssetReceiptPolicy)->assetMetadata($key, $receipt);
    }

    public function assetResource(VerifiedReceipt $receipt): string
    {
        return (new GeminiAssetReceiptPolicy)->assetResource($receipt);
    }
}
