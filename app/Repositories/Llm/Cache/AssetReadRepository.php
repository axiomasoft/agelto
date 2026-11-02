<?php

declare(strict_types=1);

namespace App\Repositories\Llm\Cache;

use App\Repositories\Llm\Request\ProviderRow;

final readonly class AssetReadRepository
{
    /** @return list<ProviderRow> */
    public function activeCaches(): array
    {
        return ProviderRow::all("SELECT p.code AS provider,a.account_id,a.api_surface,a.model,a.provider_name,a.expires_at
            FROM llm_context_caches a JOIN llm_providers p ON p.id=a.provider_id
            WHERE a.creation_state='active' AND a.expires_at>clock_timestamp() ORDER BY a.expires_at,a.id LIMIT 200");
    }

    /** @return list<ProviderRow> */
    public function cacheHits(int $hours): array
    {
        return ProviderRow::all("SELECT r.purpose,count(*) AS calls,
            sum((c.usage->>'uncached_input')::bigint) AS prompt_tokens,sum((c.usage->>'cached_input')::bigint) AS cached_tokens
            FROM llm_provider_calls c JOIN llm_provider_requests r ON r.logical_request_id=c.logical_request_id
            WHERE coalesce(c.sent_at,c.send_claimed_at)>=clock_timestamp()-(? * interval '1 hour') AND c.usage_status='known'
            GROUP BY r.purpose ORDER BY r.purpose", [$hours]);
    }
}
