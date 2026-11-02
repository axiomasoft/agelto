<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai;

use App\Dto\Llm\Requests\PinnedLlmContext;
use App\Dto\Llm\Requests\PricingSnapshot;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Services\Procurement\Network\UrlPolicy;

/** Operational binding cannot change the pinned semantic provider/account/API/model identity. */
final readonly class AnalysisProviderConnection
{
    public UrlPolicy $urlPolicy;

    public function __construct(public PinnedLlmContext $context, public string $origin, public PricingSnapshot $pricing,
        public string $periodId, public RequestProfile $profile, private string $apiKey, ?UrlPolicy $urlPolicy = null)
    {
        $this->urlPolicy = $urlPolicy ?? new UrlPolicy([CanonicalOrigin::fromUrl($origin)]);
    }

    public function credential(): string
    {
        return $this->apiKey;
    }
}
