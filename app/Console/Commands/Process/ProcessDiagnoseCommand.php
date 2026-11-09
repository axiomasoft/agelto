<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Platform\PlatformCode;
use App\Enums\Procurement\Health\PlatformCheckKind;
use App\Models\Procurement\Platform\Platform;
use App\Repositories\Procurement\Process\LedgerRow;
use App\Services\Procurement\Acquisition\AdapterDiagnosticRunner;
use App\Services\Procurement\Health\PlatformHealthChecker;
use App\Services\Procurement\Network\OutboundPolicy;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Запускает healthcheck-проверки площадки по штатным данным')]
#[Signature('process:diagnose
        {platform : Код площадки}
        {--kind= : Одна проверка: card_parse, listing_shape, estimate_fields или listing_filter}
        {--dry-run : Не записывать историю пассивных проверок}
        {--adapter= : Полная проверка контракта listing или detail без ingestion}
        {--crawler-id= : Профиль для проверки listing}
        {--procurement-id= : Закупка для проверки detail}
        {--release-input : Создать новый audited Process intent для failed_item карточки}
        {--reset-version= : Ожидаемая state_version для явного снятия adapter hold}
        {--actor-id= : Оператор с правом управления состоянием источника}
        {--reason= : Причина ручного восстановления}
        {--proxy-budgets : Показать бюджеты прокси, профили стадий и фактическую нагрузку за 5/60 минут}')]
final class ProcessDiagnoseCommand extends Command
{
    public function __construct(
        private readonly PlatformHealthChecker $checker,
        private readonly ?AdapterDiagnosticRunner $adapterRunner = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((bool) $this->option('proxy-budgets')) {
            return $this->diagnoseProxyBudgets((string) $this->argument('platform'));
        }
        if ($this->option('adapter') !== null) {
            try {
                $report = ($this->adapterRunner ?? app(AdapterDiagnosticRunner::class))->diagnose(
                    (string) $this->argument('platform'), $this->option('adapter'), (bool) $this->option('dry-run'),
                    $this->option('crawler-id'), $this->option('procurement-id'), (bool) $this->option('release-input'),
                    $this->option('reset-version'), $this->option('actor-id'), $this->option('reason'),
                );
            } catch (\Throwable $exception) {
                $this->error('Контракт адаптера не принят: '.$exception->getMessage());

                return self::FAILURE;
            }
            if ($report['reset_info'] !== null) {
                $this->info($report['reset_info']);
            }
            if ($report['level'] !== null && $report['message'] !== null) {
                $this->{$report['level']}($report['message']);
            }

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        try {
            $platformOption = $this->argument('platform');
            if ($platformOption === '') {
                throw new \InvalidArgumentException('Код площадки должен быть непустой строкой.');
            }
            $kind = $this->option('kind');
            $checkKind = is_string($kind) && $kind !== '' ? PlatformCheckKind::from($kind) : null;
            $platform = Platform::query()->where('code', $platformOption)->firstOrFail();
            $to = now();
            $windowHours = config('process.diagnostics.window_hours');
            if (! is_int($windowHours) || $windowHours <= 0) {
                throw new \UnexpectedValueException('process.diagnostics.window_hours must be a positive integer.');
            }
            $results = $this->checker->run($platform, $to->copy()->subHours($windowHours), $to, $checkKind, (bool) $this->option('dry-run'));
        } catch (\Throwable $exception) {
            $this->warn("Healthcheck не выполнен: {$exception->getMessage()}");

            return self::FAILURE;
        }

        foreach ($results as $result) {
            $line = "{$result->kind->value}: {$result->verdict->value}; {$result->message}";
            $result->verdict->value === 'ok' ? $this->info($line) : $this->warn($line);
        }

        return self::SUCCESS;
    }

    private function diagnoseProxyBudgets(string $platformCode): int
    {
        try {
            $definition = app(PlatformRegistry::class)->get(new PlatformCode($platformCode));
            $outboundPolicy = app(OutboundPolicy::class);
        } catch (\Throwable $exception) {
            $this->error('Не удалось загрузить конфигурацию площадки: '.$exception->getMessage());

            return self::FAILURE;
        }

        $stageProfiles = [];
        foreach (['listing', 'detail', 'status_refresh'] as $stage) {
            $configuredKey = $definition->stageRouteProfiles[$stage] ?? null;
            $hasMembers = $configuredKey !== null && (LedgerRow::one(
                'SELECT count(*) AS n FROM prc_network_route_profiles p
                 JOIN prc_network_route_profile_members m ON m.profile_id = p.id
                 JOIN prc_network_routes r ON r.id = m.route_id
                 WHERE p.profile_key = ? AND r.enabled = true',
                [$configuredKey],
            )?->int('n') ?? 0) > 0;
            $effectiveKey = $hasMembers ? $configuredKey : $outboundPolicy->defaultRouteProfile;
            $stageProfiles[$stage] = $effectiveKey;
            $this->info(sprintf(
                'Стадия %s: профиль=%s%s',
                $stage,
                $effectiveKey,
                $configuredKey !== null && ! $hasMembers ? sprintf(' (fallback от %s — пустой профиль)', $configuredKey) : '',
            ));
        }

        foreach ($definition->sourceTargets->origins() as $origin) {
            $originHash = $origin->hash()->hex;
            $originBudget = LedgerRow::one(
                'SELECT emission_interval_us, burst, max_inflight,
                    CASE WHEN not_before > clock_timestamp() THEN not_before::text ELSE NULL END AS cooldown_until
                 FROM prc_network_budgets WHERE scope_key = ?',
                [$origin->budgetScopeKey()],
            );
            $fallbackOriginPolicy = $definition->sourceTargets->originBudget($origin, $outboundPolicy->originBudget);
            $originIntervalUs = $originBudget?->int('emission_interval_us') ?? $fallbackOriginPolicy->emissionIntervalUs();
            $originRate = intdiv(59_999_999, $originIntervalUs) + 1;
            $originBurst = $originBudget?->int('burst') ?? $fallbackOriginPolicy->burst;
            $originInflight = $originBudget?->int('max_inflight') ?? $fallbackOriginPolicy->maxInflight;
            $originCooldown = $originBudget?->nullableString('cooldown_until');

            $this->line(sprintf(
                'Origin %s://%s:%d: rate_per_minute=%d, emission_interval_us=%d, burst=%d, max_inflight=%d, статус=%s',
                $origin->scheme,
                $origin->host,
                $origin->port,
                $originRate,
                $originIntervalUs,
                $originBurst,
                $originInflight,
                $originCooldown !== null ? 'cooldown до '.$originCooldown : 'укладывается в бюджет',
            ));

            $fallbackRoutePolicy = $definition->sourceTargets->routeOriginBudget($origin, $outboundPolicy->routeOriginBudget);
            $routes = LedgerRow::all(
                "SELECT
                    r.id::text AS route_id,
                    r.route_key,
                    r.kind,
                    r.egress_id,
                    r.enabled,
                    px.host AS proxy_host,
                    px.port AS proxy_port,
                    COALESCE((
                        SELECT string_agg(p.profile_key, ',' ORDER BY p.profile_key)
                        FROM prc_network_route_profile_members m
                        JOIN prc_network_route_profiles p ON p.id = m.profile_id
                        WHERE m.route_id = r.id
                    ), '-') AS profiles,
                    COALESCE(max(b.emission_interval_us), ?) AS emission_interval_us,
                    COALESCE(min(b.burst), ?) AS burst,
                    COALESCE(min(b.max_inflight), ?) AS max_inflight,
                    max(CASE WHEN b.not_before > clock_timestamp() THEN b.not_before ELSE NULL END)::text AS cooldown_until,
                    COALESCE((
                        SELECT count(*) FROM prc_request_attempts a
                        JOIN prc_source_targets t ON t.id = a.target_id
                        WHERE a.route_id = r.id AND t.canonical_origin = ?
                          AND a.sent_at >= clock_timestamp() - interval '5 minutes'
                    ), 0) AS req_5m,
                    COALESCE((
                        SELECT count(*) FROM prc_request_attempts a
                        JOIN prc_source_targets t ON t.id = a.target_id
                        WHERE a.route_id = r.id AND t.canonical_origin = ?
                          AND a.sent_at >= clock_timestamp() - interval '60 minutes'
                    ), 0) AS req_60m,
                    COALESCE((
                        SELECT count(*) FROM prc_request_attempts a
                        JOIN prc_source_targets t ON t.id = a.target_id
                        WHERE a.route_id = r.id AND t.canonical_origin = ?
                          AND a.status IN (403, 429)
                          AND a.sent_at >= clock_timestamp() - interval '5 minutes'
                    ), 0) AS waf_5m,
                    COALESCE((
                        SELECT count(*) FROM prc_request_attempts a
                        JOIN prc_source_targets t ON t.id = a.target_id
                        WHERE a.route_id = r.id AND t.canonical_origin = ?
                          AND a.status IN (403, 429)
                          AND a.sent_at >= clock_timestamp() - interval '60 minutes'
                    ), 0) AS waf_60m
                 FROM prc_network_routes r
                 LEFT JOIN prc_proxies px ON px.route_id = r.id
                 LEFT JOIN prc_network_budgets b ON b.scope_key IN (
                     'route-origin:' || r.id::text || ':' || ?,
                     'egress-origin:' || r.egress_id || ':' || ?
                 )
                 GROUP BY r.id, r.route_key, r.kind, r.egress_id, r.enabled, px.host, px.port
                 ORDER BY r.kind DESC, r.route_key ASC",
                [
                    $fallbackRoutePolicy->emissionIntervalUs(),
                    $fallbackRoutePolicy->burst,
                    $fallbackRoutePolicy->maxInflight,
                    $origin->value(),
                    $origin->value(),
                    $origin->value(),
                    $origin->value(),
                    $originHash,
                    $originHash,
                ],
            );

            $tableRows = [];
            foreach ($routes as $row) {
                $intervalUs = $row->int('emission_interval_us');
                $ratePerMinute = intdiv(59_999_999, $intervalUs) + 1;
                $burst = $row->int('burst');
                $maxInflight = $row->int('max_inflight');
                $req5m = $row->int('req_5m');
                $req60m = $row->int('req_60m');
                $waf5m = $row->int('waf_5m');
                $waf60m = $row->int('waf_60m');
                $cooldown = $row->nullableString('cooldown_until');
                $enabled = $row->bool('enabled');
                $profileKeys = explode(',', $row->string('profiles'));
                $stagesForRoute = [];
                foreach ($stageProfiles as $st => $profKey) {
                    if (in_array($profKey, $profileKeys, true)) {
                        $stagesForRoute[] = $st;
                    }
                }
                $endpointLabel = $row->nullableString('proxy_host') !== null
                    ? $row->string('proxy_host').':'.$row->int('proxy_port')
                    : $row->string('route_key');

                if (! $enabled) {
                    $status = 'отключён';
                } elseif ($cooldown !== null) {
                    $status = sprintf('cooldown до %s (403/429: %d/%d)', $cooldown, $waf5m, $waf60m);
                } elseif ($req5m > ($ratePerMinute * 5 + $burst) || $req60m > ($ratePerMinute * 60 + $burst)) {
                    $status = sprintf('превышен лимит (403/429: %d/%d)', $waf5m, $waf60m);
                } else {
                    $status = sprintf('укладывается в бюджет (403/429: %d/%d)', $waf5m, $waf60m);
                }

                $tableRows[] = [
                    $endpointLabel,
                    $row->string('egress_id'),
                    $row->string('profiles'),
                    $stagesForRoute === [] ? '-' : implode(',', $stagesForRoute),
                    (string) $intervalUs,
                    (string) $ratePerMinute,
                    (string) $burst,
                    (string) $maxInflight,
                    sprintf('%d / %d', $req5m, $req60m),
                    sprintf('%d / %d', $waf5m, $waf60m),
                    $status,
                ];
            }

            $this->table(
                ['Прокси / Маршрут', 'Egress ID', 'Профили', 'Стадии', 'emission_interval_us', 'rate_per_minute', 'burst', 'max_inflight', 'Запросы (5м / 60м)', '403/429 (5м / 60м)', 'Статус'],
                $tableRows,
            );
        }

        return self::SUCCESS;
    }
}
