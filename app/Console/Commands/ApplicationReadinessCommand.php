<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Health\ReadinessCheckCode;
use App\Services\Health\ApplicationReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[Description('Run active application readiness checks')]
#[AsCommand(name: 'app:readiness')]
#[Signature('app:readiness {--format=agent : agent or json} {--only= : Comma-separated readiness check codes} {--isolated : Run readiness probe in a hard-bounded child process}')]
final class ApplicationReadinessCommand extends Command
{
    public function handle(ApplicationReadiness $readiness): int
    {
        $format = (string) $this->option(key: 'format');

        if (! in_array($format, ['agent', 'json'], true)) {
            $this->components->error('The format must be agent or json.');

            return self::INVALID;
        }

        $onlyOption = $this->option(key: 'only');
        $only = null;
        if (is_string($onlyOption) && trim($onlyOption) !== '') {
            $only = [];
            foreach (array_filter(array_map('trim', explode(',', $onlyOption)), static fn (string $v): bool => $v !== '') as $rawCode) {
                $code = ReadinessCheckCode::tryFrom($rawCode);
                if ($code === null) {
                    $this->components->error('Unknown readiness check code.');

                    return self::INVALID;
                }
                $only[] = $code;
            }
        }

        $results = (bool) $this->option(key: 'isolated')
            ? $readiness->checkIsolated($only)
            : ($only === null ? $readiness->check() : $readiness->check($only));
        $payload = array_map(fn ($result): array => $result->toArray(), $results);

        $this->line(json_encode($payload, JSON_THROW_ON_ERROR));

        return collect($results)->every(fn ($result): bool => $result->passed)
            ? self::SUCCESS
            : self::FAILURE;
    }
}
