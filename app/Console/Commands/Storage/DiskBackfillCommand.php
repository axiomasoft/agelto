<?php

declare(strict_types=1);

namespace App\Console\Commands\Storage;

use App\Services\Storage\DiskBackfillRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * `--materialize-only` проставляет `null` → фактическое имя диска и ОБЯЗАНА запускаться ДО флипа
 * конфиг-ключа диска носителя (D51); `--to=<disk>` переносит объекты между дисками с верификацией
 * sha256/размера, resumable через `--from-id` (P6.5, `research/07-storage-addressing.md` §6).
 */
#[Description('Backfill дисков: --materialize-only проставляет фактическое имя диска и ОБЯЗАНА запускаться ДО флипа конфига; --to=<disk> переносит объекты между дисками с верификацией sha256/размера')]
#[Signature('storage:disk-backfill
        {carrier : document-versions|fixtures|estimate-raw-results}
        {--materialize-only : проставить фактическое имя диска без копирования — запускать ДО флипа конфига}
        {--to= : целевой диск переноса}
        {--from-id=1 : возобновление с этого id (курсор из предыдущего прогона)}
        {--dry-run : не писать в БД и не копировать объекты, только отчёт}')]
final class DiskBackfillCommand extends Command
{
    public function __construct(private readonly DiskBackfillRunner $runner)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $carrier = (string) $this->argument('carrier');

        if (! in_array($carrier, DiskBackfillRunner::CARRIERS, true)) {
            $this->error(sprintf('Неизвестный носитель "%s". Допустимо: %s', $carrier, implode(', ', DiskBackfillRunner::CARRIERS)));

            return self::FAILURE;
        }

        $fromId = (int) $this->option('from-id');
        $dryRun = (bool) $this->option('dry-run');
        $materializeOnly = (bool) $this->option('materialize-only');
        $to = $this->option('to');

        if ($materializeOnly && $to !== null) {
            $this->error('--materialize-only и --to взаимоисключающие');

            return self::FAILURE;
        }

        if ($materializeOnly) {
            $this->warn('Материализация проставляет диск, актуальный ПРЯМО СЕЙЧАС, — обязана запускаться ДО флипа конфиг-ключа диска носителя.');

            return $this->reportMaterialize($this->runner->materialize($carrier, $fromId, $dryRun));
        }

        if ($to === null) {
            $this->error('Без --materialize-only обязателен --to=<disk>');

            return self::FAILURE;
        }

        return $this->reportTransfer($this->runner->transfer($carrier, (string) $to, $fromId, $dryRun));
    }

    /**
     * @param  array{mode: 'materialize', processed: int, materialized: int, cursor: int|null}  $report
     */
    private function reportMaterialize(array $report): int
    {
        $this->info(sprintf(
            'обработано: %d · материализовано: %d · курсор: %s',
            $report['processed'],
            $report['materialized'],
            $report['cursor'] !== null ? (string) $report['cursor'] : '—',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{mode: 'transfer', processed: int, copied: int, skipped: int, discrepancies: list<array{id: int, reason: string}>, cursor: int|null}  $report
     */
    private function reportTransfer(array $report): int
    {
        $this->info(sprintf(
            'обработано: %d · скопировано: %d · пропущено: %d · расхождений: %d · курсор: %s',
            $report['processed'],
            $report['copied'],
            $report['skipped'],
            count($report['discrepancies']),
            $report['cursor'] !== null ? (string) $report['cursor'] : '—',
        ));

        if ($report['discrepancies'] !== []) {
            $this->table(['id', 'расхождение'], array_map(
                static fn (array $d): array => [$d['id'], $d['reason']],
                $report['discrepancies'],
            ));
        }

        return $report['discrepancies'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
