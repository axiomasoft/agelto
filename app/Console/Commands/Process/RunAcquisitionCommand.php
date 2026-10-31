<?php

declare(strict_types=1);

namespace App\Console\Commands\Process;

use App\Dto\Procurement\Process\ProcessUuid;
use App\Enums\Procurement\Process\AcquisitionKind;
use App\Models\Procurement\Crawler\Crawler;
use App\Services\Procurement\Acquisition\AcquisitionProfiles;
use App\Services\Procurement\Acquisition\AcquisitionScheduler;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

#[Signature('process:run {profile : Profile ID or name} {--kind=fresh} {--command-id=} {--from=} {--to=}')]
final class RunAcquisitionCommand extends Command
{
    public function handle(AcquisitionProfiles $profiles, AcquisitionScheduler $scheduler): int
    {
        try {
            $identifier = (string) $this->argument('profile');
            $profile = ctype_digit($identifier) ? Crawler::query()->findOrFail((int) $identifier)
                : Crawler::query()->where('name', $identifier)->firstOrFail();
            $kind = AcquisitionKind::from((string) $this->option('kind'));
            $command = new ProcessUuid((string) ($this->option('command-id') ?? Str::uuid()));
            $from = $this->option('from');
            $through = $this->option('to');
            $launch = $profiles->prepare($profile, $kind, is_string($from) ? new \DateTimeImmutable($from) : null,
                is_string($through) ? new \DateTimeImmutable($through) : null);
            $id = $scheduler->manual($launch, $command, null);
            $this->line('run='.$id->value.' command='.$command->value);

            return self::SUCCESS;
        } catch (ModelNotFoundException) {
            $this->error('Acquisition profile not found.');

            return self::FAILURE;
        } catch (\InvalidArgumentException|\ValueError|\DateMalformedStringException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
