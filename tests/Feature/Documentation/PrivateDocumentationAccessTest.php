<?php

declare(strict_types=1);

use App\Guards\Admin\Permissions\DocumentationPermission;
use App\Guards\Admin\Roles\AnalystRole;
use App\Guards\Admin\Roles\OperatorRole;
use App\Guards\Admin\Roles\SuperAdminRole;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Auth;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    $this->documentationRoot = sys_get_temp_dir().'/agelto-documentation-access-'.bin2hex(random_bytes(8));
    mkdir($this->documentationRoot.'/site', recursive: true);
    file_put_contents($this->documentationRoot.'/site/index.html', '<main>private documentation sentinel</main>');
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        [
            'path' => 'index.html',
            'mime' => 'text/html; charset=utf-8',
            'sha256' => hash_file('sha256', $this->documentationRoot.'/site/index.html'),
        ],
    ], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    config(['documentation.root' => $this->documentationRoot]);

    artisan('guard:sync-roles');
});

afterEach(function (): void {
    privateDocumentationAccessRemoveDirectory($this->documentationRoot);
});

test('guest redirects to the login without documentation bytes', function (): void {
    $this->get('/documentation')
        ->assertRedirect(route(name: 'filament.admin.auth.login'))
        ->assertDontSee('private documentation sentinel');
});

test('roleless analyst and operator are denied documentation bytes on GET and HEAD', function (): void {
    $roleless = User::factory()->create();
    $analyst = User::factory()->create();
    $operator = User::factory()->create();
    $analyst->assignRole(AnalystRole::class);
    $operator->assignRole(OperatorRole::class);

    foreach ([$roleless, $analyst, $operator] as $user) {
        actingAs($user);

        $this->get('/documentation')
            ->assertForbidden()
            ->assertDontSee('private documentation sentinel');
        $this->call('HEAD', '/documentation')->assertForbidden();
    }
});

test('superadmin can access documentation and sees its navigation item', function (): void {
    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);
    actingAs($user);

    $this->get('/documentation')->assertOk();
    $this->call('HEAD', '/documentation')->assertOk();

    expect(privateDocumentationNavigationItem()->isVisible())->toBeTrue()
        ->and(privateDocumentationNavigationItem()->getUrl())->toBe(route(name: 'documentation.show'));
});

test('admin-scoped direct grant opens documentation and revocation closes it again', function (): void {
    $user = User::factory()->create();
    $user->guard('admin')->grantPermission(DocumentationPermission::View);
    actingAs($user);

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->get('/documentation')->assertOk();
    $this->call('HEAD', '/documentation')->assertOk();
    expect(privateDocumentationNavigationItem()->isVisible())->toBeTrue();

    $user->guard('admin')->revokePermission(DocumentationPermission::View);

    $this->get('/documentation')
        ->assertForbidden()
        ->assertDontSee('private documentation sentinel');
    $this->call('HEAD', '/documentation')->assertForbidden();
    expect(privateDocumentationNavigationItem()->isVisible())->toBeFalse();
});

test('guest and revoked users cannot recover cached documentation bytes through a direct asset URL', function (): void {
    $user = User::factory()->create();
    $user->guard('admin')->grantPermission(DocumentationPermission::View);
    actingAs($user);

    $response = $this->get('/documentation')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->toContain('no-store');

    $user->guard('admin')->revokePermission(DocumentationPermission::View);

    $this->get('/documentation')
        ->assertForbidden()
        ->assertDontSee('private documentation sentinel');

    Auth::logout();
    $this->flushSession();

    $this->get('/documentation')
        ->assertRedirect(route(name: 'filament.admin.auth.login'))
        ->assertDontSee('private documentation sentinel');
});

function privateDocumentationNavigationItem(): NavigationItem
{
    $item = collect(Filament::getPanel('admin')->getNavigationItems())
        ->first(fn (NavigationItem $item): bool => $item->getLabel() === 'Документация');

    expect($item)->toBeInstanceOf(NavigationItem::class);

    return $item;
}

function privateDocumentationAccessRemoveDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink()
            ? rmdir($entry->getPathname())
            : unlink($entry->getPathname());
    }

    rmdir($directory);
}
