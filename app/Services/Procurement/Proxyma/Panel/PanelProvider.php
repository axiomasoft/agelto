<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Panel;

use App\Services\Procurement\Proxyma\Caller\AdapterSendGuard;
use App\Services\Procurement\Proxyma\Catalog\BindingAdapter;
use Proxyma\Application\Extensions\DefinitionRegistry;
use Proxyma\Contracts\ExtensionProvider;
use Proxyma\Domain\Execution\AdmissionLockTier;
use Proxyma\Domain\Panels\ExtensionDefinition;
use Proxyma\Domain\Panels\ExtensionKind;
use Proxyma\Domain\Panels\PanelSlot;
use Proxyma\Domain\Panels\SubjectResolverDefinition;
use Proxyma\Domain\Policy\PanelDefinition;

final readonly class PanelProvider implements ExtensionProvider
{
    public function __construct(private BindingAdapter $bindings, private AdapterSendGuard $guard) {}

    public function register(DefinitionRegistry $registry): void
    {
        $registry->registerSubjectResolver(new SubjectResolverDefinition('agelto.subjects', $this->bindings,
            ['crawler', 'procurement', 'registry', 'platform', 'provider', 'system']));
        foreach (['acquisition' => ['listing', 'detail', 'status_refresh'], 'document' => ['download'], 'reference' => ['reference'], 'provider' => ['llm'], 'notification' => ['notification']] as $panel => $slots) {
            $registry->registerPanel(new PanelDefinition('agelto.'.$panel, 'agelto.subjects',
                array_map(static fn (string $slot): PanelSlot => new PanelSlot($slot,
                    ['timeout.connect_ms', 'timeout.read_ms', 'timeout.total_ms', 'retry.max_attempts',
                        'retry.delays_ms', 'http.max_redirects', 'route.allow_direct', 'routes.denied', 'destinations.allowed', 'route.strategy']), $slots)));
        }
        $registry->registerExtension(new ExtensionDefinition('agelto.adapter', ExtensionKind::LockedAdmissionGuard,
            $this->guard, tier: AdmissionLockTier::AdapterGuard));
    }
}
