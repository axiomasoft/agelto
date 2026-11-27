<?php

declare(strict_types=1);

namespace Database\Seeders\User;

use Illuminate\Database\Seeder;
use LogicException;

/** @deprecated Use admin:create-initial or admin:reset-password instead. */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        throw new LogicException('AdminUserSeeder больше не выполняет мутации; используйте явные admin:create-initial или admin:reset-password.');
    }
}
