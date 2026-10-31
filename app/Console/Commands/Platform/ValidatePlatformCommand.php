<?php

declare(strict_types=1);

namespace App\Console\Commands\Platform;

use App\Dto\Procurement\Platform\PlatformCode;
use App\Services\Procurement\Platform\PlatformRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;

#[Description('Проверка platform definitions и policy без DB/network I/O')]
#[Signature('platform:validate {code? : Код площадки}')]
final class ValidatePlatformCommand extends Command
{
    public function handle(Repository $config): int
    {
        try {
            $registry = new PlatformRegistry($config);
            $code = $this->argument('code');
            $definitions = is_string($code) ? [$registry->get(new PlatformCode($code))] : $registry->all();
            foreach ($definitions as $definition) {
                $this->info($definition->code->value.': definition '.$definition->version->value.' valid');
                $this->line('  source targets: '.count($definition->sourceTargets->targets));
            }
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
