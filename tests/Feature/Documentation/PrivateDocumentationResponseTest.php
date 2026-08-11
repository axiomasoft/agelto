<?php

declare(strict_types=1);

use App\Guards\Admin\Roles\SuperAdminRole;
use App\Http\Controllers\Documentation\PrivateDocumentationController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

beforeEach(function (): void {
    $this->documentationRoot = sys_get_temp_dir().'/agelto-documentation-response-'.bin2hex(random_bytes(8));
    mkdir($this->documentationRoot.'/site/assets', recursive: true);
    file_put_contents($this->documentationRoot.'/site/index.html', '<script>bootstrap()</script><main>root sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/guide.html', '<main>nested sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/assets/app.css', 'body { color: black; }');
    file_put_contents($this->documentationRoot.'/site/assets/app.js', 'window.documentationReady = true;');
    file_put_contents($this->documentationRoot.'/site/assets/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    file_put_contents($this->documentationRoot.'/site/assets/portal.woff2', 'font sentinel');
    file_put_contents($this->documentationRoot.'/site/assets/search.json', '{"items":[]}');
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'assets/app.css', 'mime' => 'text/css; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/app.css')],
        ['path' => 'assets/app.js', 'mime' => 'text/javascript; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/app.js')],
        ['path' => 'assets/logo.svg', 'mime' => 'image/svg+xml', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/logo.svg')],
        ['path' => 'assets/portal.woff2', 'mime' => 'font/woff2', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/portal.woff2')],
        ['path' => 'assets/search.json', 'mime' => 'application/json; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/search.json')],
        ['path' => 'guide.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/guide.html')],
        ['path' => 'index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/index.html')],
    ], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    config(['documentation.root' => $this->documentationRoot]);

    artisan('guard:sync-roles');
    $user = User::factory()->create();
    $user->assignRole(SuperAdminRole::class);
    actingAs($user);
});

afterEach(function (): void {
    privateDocumentationResponseRemoveDirectory($this->documentationRoot);
});

test('response policy preserves manifest body mime headers and html csp', function (): void {
    $response = $this->get('/admin/documentation');

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toContain('root sentinel')
        ->and($response->headers->get('Content-Type'))->toBe('text/html; charset=utf-8')
        ->and(privateDocumentationCacheDirectives($response->headers->get('Cache-Control')))->toBe(['no-store', 'private'])
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cross-Origin-Resource-Policy'))->toBe('same-origin')
        ->and($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and($response->headers->get('Content-Security-Policy'))->toContain("script-src 'self' 'unsafe-inline'");
});

test('representative static artifacts share the manifest mime and no-store policy', function (string $path, string $mime, string $contents): void {
    $response = $this->get("/admin/documentation/{$path}");

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($contents)
        ->and($response->headers->get('Content-Type'))->toBe($mime)
        ->and(privateDocumentationCacheDirectives($response->headers->get('Cache-Control')))->toBe(['no-store', 'private'])
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
})->with([
    'CSS' => ['assets/app.css', 'text/css; charset=utf-8', 'body { color: black; }'],
    'JavaScript' => ['assets/app.js', 'text/javascript; charset=utf-8', 'window.documentationReady = true;'],
    'JSON' => ['assets/search.json', 'application/json; charset=utf-8', '{"items":[]}'],
    'SVG' => ['assets/logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>'],
    'font' => ['assets/portal.woff2', 'font/woff2', 'font sentinel'],
]);

test('nested manifest page is served and missing page has the uniform 404 surface', function (): void {
    $nested = $this->get('/admin/documentation/guide.html');

    expect(file_get_contents($nested->baseResponse->getFile()->getPathname()))->toContain('nested sentinel')
        ->and($nested->headers->get('Content-Type'))->toBe('text/html; charset=utf-8');

    $this->get('/admin/documentation/missing.html')
        ->assertNotFound()
        ->assertDontSee('root sentinel')
        ->assertDontSee('nested sentinel');
});

test('only the declared GET HEAD documentation route exists and remains fail closed', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'admin/documentation'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->methods())->toBe(['GET', 'HEAD'])
        ->and($routes->first()->getActionName())->toBe(PrivateDocumentationController::class);

    actingAs(User::factory()->create());

    $this->get('/admin/documentation')->assertForbidden();
    $this->call('HEAD', '/admin/documentation')->assertForbidden();
});

function privateDocumentationResponseRemoveDirectory(string $directory): void
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

/**
 * @return list<string>
 */
function privateDocumentationCacheDirectives(?string $header): array
{
    $directives = array_map(trim(...), explode(',', (string) $header));
    sort($directives, SORT_STRING);

    return $directives;
}
