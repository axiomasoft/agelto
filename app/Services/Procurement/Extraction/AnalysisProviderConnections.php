<?php

declare(strict_types=1);

namespace App\Services\Procurement\Extraction;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Procurement\Ai\AnalysisProviderConnection;
use App\Exceptions\Llm\OperationDenied;
use Illuminate\Container\Attributes\Singleton;

/** Explicit provisioned account/pricing bindings; missing configuration fails before authorization. */
#[Singleton]
final class AnalysisProviderConnections
{
    /** @var array<string,AnalysisProviderConnection> */
    private array $connections = [];

    private ?PinnedLlmContext $preferred = null;

    public function register(AnalysisProviderConnection $connection, bool $preferred = false): void
    {
        $key = hash('sha256', $connection->context->json);
        if (isset($this->connections[$key])) {
            throw new \LogicException('Duplicate immutable provider connection.');
        }
        if ($preferred && $this->preferred !== null) {
            throw new \LogicException('Only one analysis profile may be selected.');
        }
        $this->connections[$key] = $connection;
        if ($preferred) {
            $this->preferred = $connection->context;
        }
    }

    public function for(PinnedLlmContext $context): AnalysisProviderConnection
    {
        return $this->connections[hash('sha256', $context->json)] ?? throw new OperationDenied('provider_configuration_hold');
    }

    public function preferred(): AnalysisProviderConnection
    {
        return $this->preferred === null ? throw new OperationDenied('provider_configuration_hold') : $this->for($this->preferred);
    }
}
