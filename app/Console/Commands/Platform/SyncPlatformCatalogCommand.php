<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Enums\Procurement\Process\StageCode;
use App\Services\Procurement\Acquisition\AcquisitionRuntime;
use App\Services\Procurement\Document\DocumentRuntime;
use App\Services\Procurement\Network\SourceCatalogSync;
use App\Services\Procurement\Process\ProcessAuthoritySync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Явный idempotent install/deploy шаг: ПОСЛЕ migrations и ДО worker activation создаёт/сверяет
 * control authority, direct route, source targets, origin budgets и начальные health rows.
 * Routine boot этого не делает. Код 0 — catalog согласован; 1 — конфликт, требующий решения
 * (retired key снова объявлен, неизвестная площадка, неверный definition): видимый
 * configuration hold вместо сотен failed works.
 */
#[Description('Идемпотентная синхронизация source catalog (targets, origin budgets, health rows) зарегистрированных площадок')]
#[Signature('platform:sync-catalog {--platform=* : код площадки} {--provider=* : provider key; без фильтров синхронизировать все catalogs}')]
final class SyncPlatformCatalogCommand extends Command
{
    public function handle(SourceCatalogSync $sync): int
    {
        $platforms = $this->option('platform');
        $codes = $platforms !== [] ? array_values(array_map(strval(...), $platforms)) : null;

        try {
            $providerKeys = array_values(array_map(strval(...), $this->option('provider')));
            $reports = $providerKeys === [] ? $sync->syncRegistered($codes) : [];
            if ($codes === null) {
                $reports = [...$reports, ...$sync->syncProviders($providerKeys === [] ? null : $providerKeys)];
            }
            if ($codes !== null && $providerKeys !== []) {
                $reports = [...$sync->syncRegistered($codes), ...$sync->syncProviders($providerKeys)];
            }
            if ($reports !== []) {
                foreach ([StageCode::Download, StageCode::Prepare] as $stage) {
                    app(ProcessAuthoritySync::class)->registerHandler($stage, DocumentRuntime::VERSION);
                }
                $authority = app(ProcessAuthoritySync::class);
                $authority->ensureBaseline();
                foreach ([StageCode::Listing, StageCode::Detail,
                    StageCode::StatusRefresh] as $stage) {
                    $authority->registerHandler($stage, AcquisitionRuntime::VERSION);
                }
            }
        } catch (\InvalidArgumentException $exception) {
            $this->error('Source catalog отклонён: '.$exception->getMessage());

            return self::FAILURE;
        }
        if ($reports === []) {
            $this->info('Нет зарегистрированных catalogs в platforms.definitions или provider configuration.');

            return self::SUCCESS;
        }

        $status = self::SUCCESS;
        foreach ($reports as $report) {
            $this->line(sprintf(
                '%s: targets +%d ~%d -%d =%d; budgets +%d; health +%d',
                $report->platformCode ?? 'provider:'.$report->providerKey?->value, $report->targetsCreated, $report->targetsUpdated, $report->targetsRetired,
                $report->targetsUnchanged, $report->budgetsCreated, $report->healthRowsCreated,
            ));
            if ($report->driftedBudgets !== []) {
                $this->warn('  budgets отличаются от definition default (override сохранён, sync не перезаписывает): '.implode(', ', $report->driftedBudgets));
            }
            if ($report->retiredDeclared !== []) {
                $this->error('  retired target keys снова объявлены definition (нужен новый key): '.implode(', ', $report->retiredDeclared));
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
