<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class EnsureAdminUserCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:ensure-admin-user-command';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return self::SUCCESS;
    }
}
