<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction\Orchestration;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Procurement\Ai\Execution\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\Definition\RequestProfile;
use App\Dto\Procurement\Process\Primitives\ProcessUuid;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Network\ResponseKind;
use App\Services\Llm\Execution\ProviderCodecs;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Extraction\Provider\AnalysisProviderConnections;
use App\Services\Procurement\Process\Infrastructure\PayloadReader;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Container\Container;

/** Pure boot boundary. No database, budget or provider session is opened here. */
#[Singleton]
final readonly class AnalysisConfiguration
{
    /** @var array<string,AnalysisProviderConnection> */
    public array $connections;

    public ?string $selected;

    public function __construct(?ProviderCodecs $codecs = null)
    {
        $container = Container::getInstance();
        $codecs ??= $container->bound(ProviderCodecs::class)
            ? $container->make(ProviderCodecs::class)
            : ProviderCodecs::default();

        $raw = config('llm.profiles_json', '{}');
        $selected = config('llm.analysis_profile');
        $credentials = config('llm.credentials', []);
        if (! is_string($raw) || ($selected !== null && ! is_string($selected)) || ! is_array($credentials)) {
            throw new \InvalidArgumentException('llm: explicit profiles, selection and credential references required.');
        }
        $selected = $selected === '' ? null : $selected;
        $profiles = PayloadReader::json($raw);
        $connections = [];
        foreach (get_object_vars($profiles->object) as $name => $entry) {
            if (! is_string($name) || ! $entry instanceof \stdClass || count($connections) >= 32) {
                throw new \InvalidArgumentException('llm.profiles_json: bounded named profiles required.');
            }
            $connections[$name] = $this->parseJsonProfile($entry, $codecs, $credentials);
        }

        if ($selected !== null && ! isset($connections[$selected])) {
            $presetConnection = $this->resolveBuiltInPreset($selected, $codecs, $credentials);
            if ($presetConnection !== null) {
                $connections[$selected] = $presetConnection;
            }
        }

        if ($selected !== null && ! isset($connections[$selected])) {
            throw new \InvalidArgumentException('llm.analysis_profile: unknown explicit profile.');
        }
        $this->connections = $connections;
        $this->selected = $selected;
    }

    public function provision(AnalysisProviderConnections $registry): void
    {
        foreach ($this->connections as $name => $connection) {
            $registry->register($connection, $name === $this->selected);
        }
    }

    /** @param array<array-key, mixed> $credentials */
    private function parseJsonProfile(\stdClass $entry, ProviderCodecs $codecs, array $credentials): AnalysisProviderConnection
    {
        $p = new PayloadReader($entry);
        $this->assertAllowedKeys(
            $entry,
            required: ['context', 'origin', 'pricing', 'period_id', 'credential_key'],
            optional: ['low_speed_seconds', 'request_timeout_seconds'],
        );
        $context = PinnedLlmContext::restore(CanonicalJson::encode($p->object('context')->object));
        if (! $codecs->has($context->provider)) {
            throw new \InvalidArgumentException('llm.profiles_json: provider has no activated owning transport.');
        }
        $price = $p->object('pricing');
        $this->assertAllowedKeys(
            $price->object,
            required: [
                'currency', 'version', 'input_max_tokens', 'output_max_tokens', 'thinking_max_tokens',
                'thinking_included_in_output', 'input_per_million', 'output_per_million', 'thinking_per_million',
                'fixed_charge', 'bound_evidence', 'valid_until',
            ],
            optional: ['cached_input_per_million'],
        );
        $included = $price->object->thinking_included_in_output;
        if (! is_bool($included)) {
            throw new \InvalidArgumentException('llm.profiles_json: thinking accounting must be explicit.');
        }
        $cachedInputPerMillion = property_exists($price->object, 'cached_input_per_million') && $price->object->cached_input_per_million !== null
            ? $price->integer('cached_input_per_million')
            : null;
        $pricing = new PricingSnapshot(
            $price->string('currency'),
            $price->string('version'),
            OperationPurpose::Generate,
            $price->integer('input_max_tokens'),
            $price->integer('output_max_tokens'),
            $price->integer('thinking_max_tokens'),
            $included,
            $price->integer('input_per_million'),
            $price->integer('output_per_million'),
            $price->integer('thinking_per_million'),
            $price->integer('fixed_charge'),
            0,
            0,
            0,
            $price->string('bound_evidence'),
            $price->string('valid_until'),
            $cachedInputPerMillion,
        );
        $period = $p->string('period_id');
        if ($period !== '') {
            new ProcessUuid($period);
        }
        $secret = $credentials[$p->string('credential_key')] ?? null;
        if (! is_string($secret) || $secret === '') {
            throw new \InvalidArgumentException('llm.credentials: selected credential reference is unavailable.');
        }
        $lowSpeedSeconds = property_exists($entry, 'low_speed_seconds') ? $p->integer('low_speed_seconds') : 180;
        $timeoutSeconds = property_exists($entry, 'request_timeout_seconds') ? $p->integer('request_timeout_seconds') : 240;
        $profile = new RequestProfile('provider.analysis', 5, $lowSpeedSeconds, $timeoutSeconds, 8_000_000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);

        return new AnalysisProviderConnection($context, $p->string('origin'), $pricing, $period, $profile, $secret);
    }

    /** @param array<array-key, mixed> $credentials */
    private function resolveBuiltInPreset(string $selected, ProviderCodecs $codecs, array $credentials): ?AnalysisProviderConnection
    {
        $presets = config('llm.profiles', []);
        $catalog = config('llm.model_pricing', []);
        if (! is_array($presets) || ! is_array($catalog) || ! isset($presets[$selected]) || ! is_array($presets[$selected])) {
            return null;
        }
        $preset = $presets[$selected];
        $credentialKey = is_string($preset['credential_key'] ?? null) ? $preset['credential_key'] : '';
        $secret = $credentials[$credentialKey] ?? null;
        // Zero-spend invariant: never activate a built-in preset when its credential is empty.
        if (! is_string($secret) || $secret === '') {
            return null;
        }
        $provider = is_string($preset['provider'] ?? null) ? $preset['provider'] : '';
        if (! $codecs->has($provider)) {
            throw new \InvalidArgumentException('llm.profiles: provider has no activated owning transport.');
        }
        $origin = is_string($preset['origin'] ?? null) ? $preset['origin'] : '';
        if ($origin === '') {
            throw new \InvalidArgumentException('llm.profiles: origin is required for selected profile '.$selected);
        }
        $model = is_string($preset['model'] ?? null) ? $preset['model'] : '';
        $modelPricing = $catalog[$model] ?? null;
        if (! is_array($modelPricing)) {
            throw new \InvalidArgumentException('llm.model_pricing: missing pricing catalog entry for model '.$model);
        }
        $maxOutputTokens = is_int($preset['max_output_tokens'] ?? null) ? $preset['max_output_tokens'] : 16384;
        $inputMaxTokens = is_int($preset['input_max_tokens'] ?? null) ? $preset['input_max_tokens'] : 128000;
        $thinkingMaxTokens = is_int($preset['thinking_max_tokens'] ?? null) ? $preset['thinking_max_tokens'] : 16384;
        $thinking = [];
        if (is_string($preset['reasoning_effort'] ?? null) && $preset['reasoning_effort'] !== '') {
            $thinking['effort'] = $preset['reasoning_effort'];
        } elseif (is_string($preset['thinking_level'] ?? null) && $preset['thinking_level'] !== '') {
            $thinking['level'] = $preset['thinking_level'];
        }
        $context = new PinnedLlmContext(
            provider: $provider,
            account: is_string($preset['account'] ?? null) ? $preset['account'] : $provider.'-primary',
            apiVersion: is_string($preset['api_version'] ?? null) ? $preset['api_version'] : 'v1',
            model: $model,
            routePolicyVersion: 'config/1',
            options: ['max_output_tokens' => $maxOutputTokens],
            thinking: $thinking,
        );
        $pricing = new PricingSnapshot(
            currency: is_string($modelPricing['currency'] ?? null) ? $modelPricing['currency'] : 'USD',
            version: 'catalog/1',
            purpose: OperationPurpose::Generate,
            inputMaxTokens: $inputMaxTokens,
            outputMaxTokens: $maxOutputTokens,
            thinkingMaxTokens: $thinkingMaxTokens,
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
        $period = is_string($preset['period_id'] ?? null) ? $preset['period_id'] : '';
        if ($period !== '') {
            new ProcessUuid($period);
        }
        $lowSpeedSeconds = is_int($preset['low_speed_seconds'] ?? null) ? $preset['low_speed_seconds'] : 180;
        $timeoutSeconds = is_int($preset['request_timeout_seconds'] ?? null) ? $preset['request_timeout_seconds'] : 240;
        $profile = new RequestProfile('provider.analysis', 5, $lowSpeedSeconds, $timeoutSeconds, 8_000_000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);

        return new AnalysisProviderConnection($context, $origin, $pricing, $period, $profile, $secret);
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private function assertAllowedKeys(\stdClass $object, array $required, array $optional): void
    {
        $actual = array_keys(get_object_vars($object));
        if (array_diff($required, $actual) !== [] || array_diff($actual, [...$required, ...$optional]) !== []) {
            throw new \InvalidArgumentException('Process payload fields do not match its closed schema.');
        }
    }
}
