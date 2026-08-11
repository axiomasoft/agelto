<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Health\ApplicationReadiness;
use Illuminate\Console\Command;

final class ApplicationReadinessCommand extends Command
{
    protected $signature = 'app:readiness {--format=agent : agent or json}';

    protected $description = 'Run active application readiness checks';

    public function handle(ApplicationReadiness $readiness): int
    {
        $format = (string) $this->option(key: 'format');

        if (! in_array($format, ['agent', 'json'], true)) {
            $this->components->error('The format must be agent or json.');

            return self::INVALID;
        }

        $results = $readiness->check();
        $payload = array_map(fn ($result): array => $result->toArray(), $results);

        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

        return collect($results)->every(fn ($result): bool => $result->passed)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
