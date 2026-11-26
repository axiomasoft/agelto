<?php

declare(strict_types=1);

namespace App\Console\Commands\Procurement\Ai;

use App\Repositories\Llm\Cache\AssetReadRepository;
use App\Repositories\Llm\Cache\AssetRepository;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:caches {action : list|prune} {--hits} {--window=24}')]
final class CachesCommand extends Command
{
    public function handle(AssetReadRepository $assets, AssetRepository $assetStore): int
    {
        if ($this->argument('action') === 'prune') {
            $pruned = $assetStore->pruneExpired();
            $this->info("Удалено истёкших ассетов: файлов {$pruned['files']}, кэшей {$pruned['caches']}.");

            return self::SUCCESS;
        }
        if ($this->argument('action') !== 'list') {
            $this->error('Unsupported action. Expected list or prune.');

            return self::FAILURE;
        }
        if ($this->option('hits')) {
            $hours = filter_var($this->option('window'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 8760]]);
            if ($hours === false) {
                $this->error('Invalid --window. Expected 1..8760 hours.');

                return self::FAILURE;
            }
            $this->table(['purpose', 'calls', 'prompt_tokens', 'cached_tokens'], array_map(static fn ($r): array => [
                $r->string('purpose'), $r->int('calls'), $r->nullableInt('prompt_tokens'), $r->nullableInt('cached_tokens')], $assets->cacheHits($hours)));
        } else {
            $this->table(['provider', 'account', 'api', 'model', 'cache', 'expires_at'], array_map(static fn ($r): array => [
                $r->string('provider'), $r->string('account_id'), $r->string('api_surface'), $r->string('model'), $r->string('provider_name'), $r->string('expires_at')], $assets->activeCaches()));
        }

        return self::SUCCESS;
    }
}
