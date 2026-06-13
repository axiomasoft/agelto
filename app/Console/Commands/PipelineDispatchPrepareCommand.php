<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Process\Pipeline\PipelineDispatcher;
use Illuminate\Console\Command;

final class PipelineDispatchPrepareCommand extends Command
{
    protected $signature = 'app:pipeline:dispatch-prepare {--limit=100 : Максимум ссылок за один запуск}';

    protected $description = 'Мост Стадия 1 → 2: по свежим PENDING-ссылкам запускает обработку документов';

    public function handle(PipelineDispatcher $dispatcher): int
    {
        $dispatched = $dispatcher->dispatchPreparationForPendingLinks((int) $this->option('limit'));

        $this->info("Задиспатчено вложений: {$dispatched}");

        return self::SUCCESS;
    }
}
