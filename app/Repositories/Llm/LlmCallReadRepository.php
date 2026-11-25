<?php

declare(strict_types=1);

namespace App\Repositories\Llm;

use App\Models\Llm\LlmCall;
use App\Repositories\Llm\Request\ProviderRow;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Generic physical-call reporting. Consumer linkage belongs to the consumer. */
final readonly class LlmCallReadRepository
{
    /** @return Builder<LlmCall> */
    public function query(): Builder
    {
        $facts = DB::table('llm_provider_calls as c')->join('llm_provider_requests as q', 'q.logical_request_id', '=', 'c.logical_request_id')
            ->join('llm_providers as p', 'p.id', '=', 'c.provider_id')->join('llm_budget_reservations as b', 'b.id', '=', 'c.reservation_id')
            ->join('llm_budget_periods as t', 't.id', '=', 'b.period_id')
            ->select('c.*', 'p.code as provider', 'q.purpose', 't.currency')
            ->selectRaw('c.physical_call_id as id, coalesce(c.sent_at,c.send_claimed_at) as called_at')
            ->selectRaw("CASE WHEN c.usage_status='known' THEN (c.usage->>'uncached_input')::bigint END as prompt_tokens")
            ->selectRaw("CASE WHEN c.usage_status='known' THEN (c.usage->>'cached_input')::bigint END as cached_tokens")
            ->selectRaw("CASE WHEN c.usage_status='known' THEN (c.usage->>'output')::bigint END as completion_tokens")
            ->selectRaw("CASE WHEN c.usage_status='known' THEN (c.usage->>'thinking')::bigint END as thinking_tokens")
            ->selectRaw("round(coalesce(c.cost_actual,b.settled_amount)*1000000)::bigint as cost_micros, CASE WHEN b.state='reserved' THEN ceil(b.upper_bound*1000000)::bigint ELSE 0 END as reserved_micros")
            ->selectRaw("c.outcome='success' as success");

        return LlmCall::query()->fromSub($facts, 'llm_provider_calls');
    }

    /**
     * Reporting rounds to integer microcurrency; original numeric(24,8) receipts remain unchanged.
     * Settled spend includes conservative settlements. Unknown usage and retained reserves remain visible.
     *
     * @param  list<string>  $axes
     * @return list<array{day:?string,provider:?string,model:?string,purpose:?string,calls:int,prompt_tokens:int,cached_tokens:int,completion_tokens:int,thinking_tokens:int,cost_micros:int,unknown_calls:int,reserved_micros:int}>
     */
    public function usageByAxes(CarbonInterface $from, CarbonInterface $to, array $axes, string $currency = 'USD'): array
    {
        if (array_diff($axes, ['day', 'provider', 'model', 'purpose']) !== [] || count($axes) !== count(array_unique($axes))
            || preg_match('/^[A-Z]{3}$/D', $currency) !== 1) {
            throw new \InvalidArgumentException('Unsupported usage axes or currency.');
        }
        $timezone = config('app.timezone');
        if (! is_string($timezone)) {
            throw new \InvalidArgumentException('app.timezone must be a string.');
        }
        $query = $this->query()->toBase()->where('called_at', '>=', $from->format('Y-m-d H:i:s.uP'))
            ->where('called_at', '<', $to->format('Y-m-d H:i:s.uP'))->where('currency', $currency)
            ->selectRaw("count(*) as calls, coalesce(sum(prompt_tokens),0) as prompt_tokens, coalesce(sum(cached_tokens),0) as cached_tokens,
                coalesce(sum(completion_tokens),0) as completion_tokens, coalesce(sum(thinking_tokens),0) as thinking_tokens, coalesce(sum(cost_micros),0) as cost_micros,
                count(*) FILTER (WHERE cost_actual IS NULL OR usage_status='unknown') as unknown_calls, coalesce(sum(reserved_micros),0) as reserved_micros");
        foreach ($axes as $axis) {
            if ($axis === 'day') {
                $query->selectRaw('(called_at AT TIME ZONE CAST(? AS text))::date as day', [$timezone])->groupBy('day')->orderBy('day');
            } else {
                $query->addSelect($axis)->groupBy($axis)->orderBy($axis);
            }
        }
        $result = [];
        foreach (ProviderRow::all($query->toSql(), $query->getBindings()) as $row) {
            $result[] = ['day' => $row->nullableString('day'), 'provider' => $row->nullableString('provider'),
                'model' => $row->nullableString('model'), 'purpose' => $row->nullableString('purpose'),
                'calls' => $row->int('calls'), 'prompt_tokens' => $row->int('prompt_tokens'),
                'cached_tokens' => $row->int('cached_tokens'), 'completion_tokens' => $row->int('completion_tokens'),
                'thinking_tokens' => $row->int('thinking_tokens'), 'cost_micros' => $row->int('cost_micros'),
                'unknown_calls' => $row->int('unknown_calls'), 'reserved_micros' => $row->int('reserved_micros')];
        }

        return $result;
    }
}
