<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma;

use Proxyma\Application\Policy\PolicyResolver as Resolver;
use Proxyma\Contracts\PolicyResolver;
use Proxyma\Domain\Execution\Uuid;
use Proxyma\Domain\Policy\EffectivePolicySnapshot;
use Proxyma\Domain\Policy\PolicyExplanation;
use Proxyma\Domain\Policy\SubjectChain;

/** Передаёт panel/slot consumer в штатный package resolver. */
final readonly class CallerPolicyResolver implements PolicyResolver
{
    public function __construct(private Resolver $resolver, private string $panel) {}

    public function resolve(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): EffectivePolicySnapshot
    {
        return $this->resolver->resolveEffective($subjects, $targetId, $trafficKey, panelKey: $this->panel, bypassCache: true)->snapshot();
    }

    public function explain(SubjectChain $subjects, Uuid $targetId, string $trafficKey = 'default'): PolicyExplanation
    {
        return $this->resolver->explain($subjects, $targetId, $trafficKey, panelKey: $this->panel);
    }
}
