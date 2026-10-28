<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\OperatorCommandResult;
use App\Dto\Procurement\Process\ProcessUuid;
use App\Dto\Procurement\Process\WorkId;
use App\Models\User;
use App\Services\Procurement\Process\ProcessOperatorCommands;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * CLI тех же typed operator commands, что и UI (observability#s04): действие идёт от имени
 * существующего пользователя, поэтому RBAC и audit actor не обходятся. Код 0 — команда settled
 * (applied/unchanged/duplicate), 1 — отказ без записей, 2 — неверные аргументы/нет прав.
 */
#[Description('Typed operator command Process-ядра: pause, resume, cancel-work, cancel-run, retry-fault, ack-incident')]
#[Signature('process:control {action : pause|resume|cancel-work|cancel-run|retry-fault|ack-incident} {target : scope key, work id, run id или incident id} {--user= : id или email оператора} {--reason= : причина для audit} {--expected= : ожидаемая control_version либо transition_seq} {--command-id= : UUID команды для dedup retry-fault}')]
final class ProcessControlCommand extends Command
{
    private const array ACTIONS = ['pause', 'resume', 'cancel-work', 'cancel-run', 'retry-fault', 'ack-incident'];

    public function handle(ProcessOperatorCommands $commands): int
    {
        $action = (string) $this->argument('action');
        $target = (string) $this->argument('target');
        $reason = trim((string) $this->option('reason'));
        $expected = $this->option('expected');
        $actor = $this->actor();

        if (! in_array($action, self::ACTIONS, true) || $actor === null || $reason === '' || ! is_string($expected) || preg_match('/^\d+$/D', $expected) !== 1) {
            $this->error('Нужны: известное действие, существующий --user, --reason и числовой --expected.');

            return self::INVALID;
        }
        $version = (int) $expected;

        try {
            $result = match ($action) {
                'pause' => $commands->pauseScope($actor, $target, $version, $reason),
                'resume' => $commands->resumeScope($actor, $target, $version, $reason),
                'cancel-work' => $commands->cancelWork($actor, new WorkId(new ProcessUuid($target)), $version, $reason),
                'cancel-run' => $commands->cancelRun($actor, new ProcessUuid($target), $version, $reason),
                'retry-fault' => $commands->retryHandlerFaultedWork($actor, new WorkId(new ProcessUuid($target)), $version, new ProcessUuid($this->commandId()), $reason),
                'ack-incident' => $commands->acknowledgeIncident($actor, new ProcessUuid($target), $version, $reason),
            };
        } catch (AuthorizationException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        } catch (\InvalidArgumentException $exception) {
            $this->error('Неверный аргумент: '.$exception->getMessage());

            return self::INVALID;
        }

        return $this->report($action, $result);
    }

    private function report(string $action, OperatorCommandResult $result): int
    {
        $line = "{$action}: {$result->status->value}";
        if ($result->controlVersion !== null) {
            $line .= " (control_version {$result->controlVersion})";
        }
        if ($result->successor !== null) {
            $line .= " (successor {$result->successor->value->value})";
        }
        if ($result->status->isSettled()) {
            $this->info($line);

            return self::SUCCESS;
        }
        $this->error($line);

        return self::FAILURE;
    }

    private function actor(): ?User
    {
        $identifier = trim((string) $this->option('user'));
        if ($identifier === '') {
            return null;
        }

        return ctype_digit($identifier) ? User::query()->find((int) $identifier) : User::query()->where('email', $identifier)->first();
    }

    private function commandId(): string
    {
        $given = $this->option('command-id');

        return is_string($given) && $given !== '' ? Str::lower($given) : Str::lower((string) Str::uuid());
    }
}
