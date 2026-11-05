<?php

declare(strict_types=1);

namespace App\Repositories\Llm\Cache;

use App\Dto\Llm\Cache\ContextCacheAsset;
use App\Dto\Llm\Cache\ContextCacheKey;
use App\Dto\Llm\Cache\FileAssetKey;
use App\Dto\Llm\Cache\ProviderFileAsset;
use App\Dto\Llm\Requests\FinancialAmount;
use App\Dto\Llm\Requests\ProviderCall;
use App\Enums\Llm\OperationPurpose;
use App\Exceptions\Llm\OperationDenied;
use App\Repositories\Llm\Request\ProviderRow;
use App\Repositories\Llm\Request\RequestRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Asset locks follow account/period/request locks. A creation token is its exact physical call. */
final readonly class AssetRepository
{
    public function __construct(private RequestRepository $requests) {}

    public function findFile(FileAssetKey $key): ?ProviderFileAsset
    {
        $row = $this->row($this->query($key));
        if ($row === null || $row->nullableString('provider_uri') === null || $row->nullableString('expires_at') === null
            || ! in_array($row->string('state'), ['PROCESSING', 'ACTIVE', 'FAILED'], true)) {
            return null;
        }

        return new ProviderFileAsset($key, $row->string('provider_uri'), $row->string('state'), $row->string('expires_at'));
    }

    public function findCache(ContextCacheKey $key): ?ContextCacheAsset
    {
        $row = $this->row($this->query($key)->where('creation_state', 'active'));
        if ($row === null || $row->nullableString('provider_name') === null || $row->nullableString('expires_at') === null) {
            return null;
        }

        return new ContextCacheAsset($key, $row->string('provider_name'), $row->string('expires_at'));
    }

    /** Exact physical creation token, or a lookup's resource plus its sealed input hash. */
    public function recoveryKey(ProviderCall $call, string $resource): FileAssetKey|ContextCacheKey
    {
        $provider = ProviderRow::one('SELECT id FROM llm_providers WHERE code=?', [$call->context->provider])
            ?? throw new OperationDenied('provider_configuration_hold');
        $keys = [];
        foreach (['llm_file_assets' => 'provider_uri', 'llm_context_caches' => 'provider_name'] as $table => $column) {
            $query = DB::table($table)->where('provider_id', $provider->int('id'))
                ->where('account_id', $call->context->account)->where('api_surface', $call->context->apiVersion);
            if ($call->purpose === OperationPurpose::Lookup) {
                $query->where($column, $resource);
            } else {
                $query->where('creation_token', $call->authorization->physicalCallId);
            }
            $query->selectRaw($table === 'llm_file_assets' ? "*,encode(content_hash,'hex') AS content_hex" :
                "*,encode(system_hash,'hex') AS system_hex,encode(schema_tool_policy_hash,'hex') AS schema_hex,
                encode(representation_hash,'hex') AS representation_hex,encode(content_ordering_hash,'hex') AS ordering_hex");
            foreach (ProviderRow::all($query->limit(2)->toSql(), $query->getBindings()) as $row) {
                $key = $table === 'llm_file_assets' ? new FileAssetKey($call->context, $row->string('content_hex'), $row->string('mime'))
                    : new ContextCacheKey($call->context, $row->string('system_hex'), $row->string('schema_hex'), $row->string('representation_hex'), $row->string('ordering_hex'));
                if ($key instanceof ContextCacheKey && $row->string('model') !== $call->context->model) {
                    throw new OperationDenied('asset_operation_identity_drift');
                }
                if ($call->purpose !== OperationPurpose::Lookup || $key->hash === $call->inputHash) {
                    $keys[] = $key;
                }
            }
        }
        if (count($keys) !== 1) {
            throw new OperationDenied('exact_asset_recovery_identity_required');
        }
        $this->assertCall($keys[0], $call, lookup: true);

        return $keys[0];
    }

    /** Expiry alone never permits stealing an uncertain remote create. */
    public function claim(FileAssetKey|ContextCacheKey $key, ProviderCall $expected): void
    {
        $call = $this->requests->lock($expected);
        $this->assertCall($key, $call);
        if ($call->claimed || $call->requestState !== 'sending') {
            throw new OperationDenied('asset_call_not_prepared');
        }
        $query = $this->query($key);
        $row = $this->row((clone $query)->selectRaw('*, (expires_at IS NOT NULL AND expires_at <= clock_timestamp()) AS is_expired')->lockForUpdate());
        $physical = $call->authorization->physicalCallId;
        $stateColumn = $key instanceof FileAssetKey ? 'state' : 'creation_state';
        if ($row !== null && $row->nullableString('creation_token') === $physical) {
            return;
        }
        if ($row !== null) {
            if ($row->nullableString('creation_token') !== null) {
                $previous = $this->requests->get($row->string('creation_token'));
                // Successful metadata must first be recovered. Unknown/orphan cost remains reserved.
                if (! in_array($previous->outcome, ['not_sent', 'known_retryable_nonbillable'], true)
                    && ! ($previous->requestState === 'completed' && ($call->purpose === OperationPurpose::CacheRefresh
                        || $row->nullableString('expires_at') !== null && $row->bool('is_expired')))) {
                    throw new OperationDenied('asset_single_flight_or_unknown');
                }
            }
            if ($row->nullableString('expires_at') !== null && ! $row->bool('is_expired')
                && ! in_array($row->string($stateColumn), ['expired', 'creating', 'FAILED'], true)
                && $call->purpose !== OperationPurpose::CacheRefresh) {
                throw new OperationDenied('asset_still_usable');
            }
            $query->update([$stateColumn => 'creating', 'creation_token' => $physical, 'creation_lease_until' => DB::raw("clock_timestamp()+interval '120 seconds'")]);
        } else {
            $values = $this->identity($key);
            $values['id'] = (string) Str::uuid();
            $values[$stateColumn] = 'creating';
            $values['creation_token'] = $physical;
            $values['creation_lease_until'] = DB::raw("clock_timestamp()+interval '120 seconds'");
            if ($key instanceof ContextCacheKey) {
                $values['cost'] = '0';
            }
            DB::table($key instanceof FileAssetKey ? 'llm_file_assets' : 'llm_context_caches')->insert($values);
        }
    }

    /** Verified metadata is applied with financial settlement in the caller's transaction. */
    public function restore(ProviderFileAsset|ContextCacheAsset $asset, ProviderCall $call): void
    {
        $key = $asset->key;
        $this->assertCall($key, $call, lookup: true);
        $row = $this->row($this->query($key)->lockForUpdate()) ?? throw new OperationDenied('asset_creation_missing');
        if ($call->purpose !== OperationPurpose::Lookup && $row->nullableString('creation_token') !== $call->authorization->physicalCallId) {
            throw new OperationDenied('asset_creation_owner_drift');
        }
        if ($call->purpose === OperationPurpose::Lookup && $row->nullableString('expires_at') !== null
            && strtotime($asset->expiresAt) > strtotime($row->string('expires_at'))) {
            throw new OperationDenied('asset_lookup_cannot_extend_ttl');
        }
        if ($asset instanceof ProviderFileAsset) {
            if ($call->purpose === OperationPurpose::Lookup && $row->nullableString('provider_uri') !== $asset->uri) {
                throw new OperationDenied('asset_lookup_identity_drift');
            }
            $this->query($key)->update(['provider_uri' => $asset->uri, 'state' => $asset->state, 'expires_at' => $asset->expiresAt]);
        } else {
            if ($call->purpose === OperationPurpose::Lookup && $row->nullableString('provider_name') !== $asset->name) {
                throw new OperationDenied('asset_lookup_identity_drift');
            }
            $cost = DB::table('llm_provider_calls')->where('physical_call_id', $call->authorization->physicalCallId)->value('cost_actual');
            if (! is_string($cost)) {
                throw new OperationDenied('asset_cost_unsettled');
            }
            $cost = FinancialAmount::normalize($cost);
            $values = ['provider_name' => $asset->name, 'creation_state' => 'active', 'expires_at' => $asset->expiresAt];
            // Lookups never overwrite the original create/storage financial fact.
            if ($call->purpose !== OperationPurpose::Lookup) {
                $values['cost'] = $call->purpose === OperationPurpose::CacheRefresh
                    ? ($row->string('creation_state') === 'creating' ? bcadd($row->decimal('cost'), $cost, 8) : $row->decimal('cost')) : $cost;
            }
            $this->query($key)->update($values);
        }
    }

    /** A verified lookup 404 invalidates a handle, never authorizes a new operation by itself. */
    public function expire(FileAssetKey|ContextCacheKey $key, ProviderCall $call): void
    {
        $this->assertCall($key, $call, lookup: true);
        if ($call->purpose !== OperationPurpose::Lookup) {
            throw new OperationDenied('asset_lookup_required');
        }
        $this->query($key)->update([$key instanceof FileAssetKey ? 'state' : 'creation_state' => 'expired', 'expires_at' => DB::raw('clock_timestamp()')]);
    }

    private function assertCall(FileAssetKey|ContextCacheKey $key, ProviderCall $call, bool $lookup = false): void
    {
        $key instanceof FileAssetKey ? $key->assertContext($call->context) : null;
        if ($key instanceof ContextCacheKey && $key->context != $call->context
            || ! in_array($call->purpose, $key instanceof FileAssetKey
                ? [OperationPurpose::FileUpload, ...($lookup ? [OperationPurpose::Lookup] : [])]
                : [OperationPurpose::CacheCreate, OperationPurpose::CacheRefresh, ...($lookup ? [OperationPurpose::Lookup] : [])], true)) {
            throw new OperationDenied('asset_operation_identity_drift');
        }
    }

    private function query(FileAssetKey|ContextCacheKey $key): Builder
    {
        return DB::table($key instanceof FileAssetKey ? 'llm_file_assets' : 'llm_context_caches')->where($this->identity($key));
    }

    private function row(Builder $query): ?ProviderRow
    {
        return ProviderRow::one($query->toSql(), $query->getBindings());
    }

    /** @return array<string, mixed> */
    private function identity(FileAssetKey|ContextCacheKey $key): array
    {
        $provider = ProviderRow::one('SELECT id FROM llm_providers WHERE code=?', [$key->context->provider])
            ?? throw new OperationDenied('provider_configuration_hold');
        $identity = ['provider_id' => $provider->int('id'), 'account_id' => $key->context->account, 'api_surface' => $key->context->apiVersion];
        if ($key instanceof FileAssetKey) {
            return [...$identity, 'content_hash' => '\\x'.$key->contentHash, 'mime' => $key->mime];
        }

        return [...$identity, 'model' => $key->context->model, 'system_hash' => '\\x'.$key->systemHash,
            'schema_tool_policy_hash' => '\\x'.$key->schemaToolPolicyHash, 'representation_hash' => '\\x'.$key->representationHash,
            'content_ordering_hash' => '\\x'.$key->contentOrderingHash];
    }
}
