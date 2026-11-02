<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Llm\OperationPurpose;
use App\Enums\Procurement\Network\ResponseKind;
use App\Services\Llm\Schema\CanonicalJson;
use App\Services\Procurement\Process\PayloadReader;
use Illuminate\Container\Attributes\Singleton;

/** Pure boot boundary. No database, budget or provider session is opened here. */
#[Singleton]
final readonly class AnalysisConfiguration
{
    /** @var array<string,AnalysisProviderConnection> */
    public array $connections;

    public ?string $selected;

    public function __construct()
    {
        $raw = config('llm.profiles_json', '{}');
        $selected = config('llm.analysis_profile');
        $credentials = config('llm.credentials', []);
        if (! is_string($raw) || ($selected !== null && ! is_string($selected)) || ! is_array($credentials)) {
            throw new \InvalidArgumentException('llm: explicit profiles, selection and credential references required.');
        }
        $profiles = PayloadReader::json($raw);
        $connections = [];
        foreach (get_object_vars($profiles->object) as $name => $entry) {
            if (! is_string($name) || ! $entry instanceof \stdClass || count($connections) >= 32) {
                throw new \InvalidArgumentException('llm.profiles_json: bounded named profiles required.');
            }
            $p = new PayloadReader($entry);
            $p->keys(['context', 'origin', 'pricing', 'period_id', 'credential_key']);
            $context = PinnedLlmContext::restore(CanonicalJson::encode($p->object('context')->object));
            if ($context->provider !== 'gemini') {
                throw new \InvalidArgumentException('llm.profiles_json: provider has no activated owning transport.');
            }
            $price = $p->object('pricing');
            $price->keys(['currency', 'version', 'input_max_tokens', 'output_max_tokens', 'thinking_max_tokens', 'thinking_included_in_output',
                'input_per_million', 'output_per_million', 'thinking_per_million', 'fixed_charge', 'bound_evidence', 'valid_until']);
            $included = $price->object->thinking_included_in_output;
            if (! is_bool($included)) {
                throw new \InvalidArgumentException('llm.profiles_json: thinking accounting must be explicit.');
            }
            $pricing = new PricingSnapshot($price->string('currency'), $price->string('version'), OperationPurpose::Generate,
                $price->integer('input_max_tokens'), $price->integer('output_max_tokens'), $price->integer('thinking_max_tokens'), $included,
                $price->integer('input_per_million'), $price->integer('output_per_million'), $price->integer('thinking_per_million'),
                $price->integer('fixed_charge'), 0, 0, 0, $price->string('bound_evidence'), $price->string('valid_until'));
            $period = $p->string('period_id');
            if ($period !== '') {
                new ProcessUuid($period);
            }
            $secret = $credentials[$p->string('credential_key')] ?? null;
            if (! is_string($secret) || $secret === '') {
                throw new \InvalidArgumentException('llm.credentials: selected credential reference is unavailable.');
            }
            $profile = new RequestProfile('provider.analysis', 5, 30, 240, 8_000_000, null, 0, 1, 1, [], ResponseKind::Binary, null, 1);
            $connections[$name] = new AnalysisProviderConnection($context, $p->string('origin'), $pricing, $period, $profile, $secret);
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
}
