<?php

declare(strict_types=1);

use App\Enums\Procurement\Process\StageCode;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcessCore\ExecutionFixtures;
use Tests\Support\ProcessCore\FinalizeFixtures;

use function Pest\Laravel\artisan;

pest()->group('t17', 'p117');

// CLI process:control использует тот же шлюз, что и UI: RBAC от имени --user, версия обязательна.

beforeEach(fn () => artisan('guard:sync-roles'));

test('T17 process:control pause/resume: код 0 для settled, 1 для конфликта версии, 2 для аргументов и RBAC', function (): void {
    FinalizeFixtures::authority([StageCode::Listing]);
    ExecutionFixtures::ensureWorkScopes(StageCode::Listing, 'manual');
    $operator = User::factory()->create();
    $operator->assignRole(OperatorRole::class);
    $analyst = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $version = (int) DB::table('prc_control_scopes')->where('scope_key', 'acquisition')->value('control_version');

    artisan('process:control', ['action' => 'pause', 'target' => 'acquisition', '--user' => (string) $operator->id, '--reason' => 'cli', '--expected' => (string) $version])
        ->expectsOutputToContain('pause: applied')->assertExitCode(0);
    artisan('process:control', ['action' => 'pause', 'target' => 'acquisition', '--user' => $operator->email, '--reason' => 'cli', '--expected' => (string) $version])
        ->expectsOutputToContain('version_conflict')->assertExitCode(1);
    artisan('process:control', ['action' => 'resume', 'target' => 'acquisition', '--user' => (string) $analyst->id, '--reason' => 'cli', '--expected' => (string) ($version + 1)])
        ->assertExitCode(2);
    artisan('process:control', ['action' => 'resume', 'target' => 'acquisition', '--user' => (string) $operator->id, '--expected' => (string) ($version + 1)])
        ->assertExitCode(2);
    artisan('process:control', ['action' => 'explode', 'target' => 'acquisition', '--user' => (string) $operator->id, '--reason' => 'x', '--expected' => '1'])
        ->assertExitCode(2);
    artisan('process:control', ['action' => 'resume', 'target' => 'acquisition', '--user' => (string) $operator->id, '--reason' => 'cli done', '--expected' => (string) ($version + 1)])
        ->expectsOutputToContain('resume: applied')->assertExitCode(0);
});
