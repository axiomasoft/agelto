<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Services\Procurement\Process\ProjectionRebuilder;
use App\Services\Procurement\Process\ProjectionRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Idempotent recovery зависшего maintenance gate после падения rebuild: снимает
 * gate только владельцу token под state FOR UPDATE, не меняя totals/receipts и не запуская work.
 * Автоматического снятия по TTL нет: чужой или устаревший token ничего не меняет.
 */
#[Description('Снимает зависший maintenance gate projector по token (totals и receipts не меняются)')]
#[Signature('process:metrics-rebuild-resume {projector : projector_key} {--expected-token= : maintenance token зависшего rebuild}')]
final class MetricsRebuildResumeCommand extends Command
{
    public function handle(ProjectionRebuilder $rebuilder, ProjectionRegistry $registry): int
    {
        $key = (string) $this->argument('projector');
        if (! array_key_exists($key, $registry->all())) {
            $this->error('Неизвестный projector. Допустимо: '.implode(', ', array_keys($registry->all())).'.');

            return self::INVALID;
        }
        try {
            $token = new ProcessUuid(strtolower((string) $this->option('expected-token')));
        } catch (\InvalidArgumentException) {
            $this->error('Нужен --expected-token=<uuid> из состояния projector.');

            return self::INVALID;
        }
        if (! $rebuilder->resume($key, $token)) {
            $this->error("Gate {$key} не снят: projector не в maintenance либо token не совпадает.");

            return self::FAILURE;
        }
        $this->info("Gate {$key} снят: mode=active, totals и receipts не изменены.");

        return self::SUCCESS;
    }
}
