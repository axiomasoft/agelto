<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Llm\Requests\FinancialAmount;
use App\Dto\Procurement\Network\ProviderKey;
use App\Dto\Procurement\Network\ProviderTargetDefinitions;
use App\Dto\Procurement\Platform\CanonicalOrigin;
use App\Dto\Procurement\Platform\RequestProfile;
use App\Dto\Procurement\Platform\SourceTargetDefinition;
use App\Enums\Procurement\Network\ResponseKind;
use App\Enums\Procurement\Process\StageCode;
use App\Repositories\Llm\Request\FinancialReservationRepository;
use App\Services\Procurement\Extraction\AnalysisConfiguration;
use App\Services\Procurement\Extraction\AnalysisRuntime;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('process:sync-analysis {--profile=} {--period-start=} {--period-end=} {--cap=} {--reason=} {--hard-exposure=}')]
final class SyncAnalysisCommand extends Command
{
    public function handle(AnalysisConfiguration $config, ProcessAuthoritySync $authority,
        FinancialReservationRepository $finance, SourceCatalogSync $catalog): int
    {
        $selected = $this->option('profile') ?? $config->selected;
        $connection = is_string($selected) ? ($config->connections[$selected] ?? null) : null;
        $options = [];
        foreach (['period-start', 'period-end', 'cap', 'reason'] as $key) {
            $value = $this->option($key);
            if (! is_string($value) || $value === '') {
                $this->error('Explicit --'.$key.' is required.');

                return self::FAILURE;
            }
            $options[$key] = $value;
        }
        $hard = $this->option('hard-exposure');
        if ($connection === null) {
            $this->error('An explicitly configured analysis profile is required.');

            return self::FAILURE;
        }
        // Validate every supplied amount/date before the first durable change.
        $cap = (new FinancialAmount($options['cap']))->value;
        if ($hard !== null) {
            new FinancialAmount($hard);
        }
        if (strtotime($options['period-start']) === false || strtotime($options['period-end']) === false
            || strtotime($options['period-start']) >= strtotime($options['period-end'])) {
            throw new \InvalidArgumentException('Invalid explicit financial period.');
        }
        $targets = [];
        foreach ($config->connections as $profile) {
            if ($profile->context->provider !== $connection->context->provider) {
                continue;
            }
            $origin = CanonicalOrigin::fromUrl($profile->origin);
            $targets[$origin->value()] = new SourceTargetDefinition('provider:'.$profile->context->provider.':'.substr($origin->hash()->hex, 0, 16),
                'provider', $origin, [$profile->profile,
                    new RequestProfile('network.probe', 5, 10, 20, 2097152, 6291456, 0, 1, 1, [], ResponseKind::Text, 'UTF-8', 1)],
                probeUrl: $origin->value().'/');
        }
        $finance->configureAccount($connection->context->account, $connection->pricing->currency, 1, $hard, 'process:sync-analysis', $options['reason']);
        $period = $finance->openPeriod($connection->context->account, $connection->pricing->currency,
            $options['period-start'], $options['period-end'], $cap);
        $authority->ensureBaseline();
        foreach ([StageCode::Screening, StageCode::Extraction, StageCode::Publication] as $stage) {
            $authority->registerHandler($stage, AnalysisRuntime::VERSION);
        }
        $catalog->syncProvider(new ProviderTargetDefinitions(new ProviderKey($connection->context->provider), array_values($targets)));
        $this->info('Analysis authorities synchronized; budget period '.$period.'. Route membership remains explicitly provisioned.');

        return self::SUCCESS;
    }
}
