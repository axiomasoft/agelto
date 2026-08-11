<?php

declare(strict_types=1);

use App\Http\Controllers\Documentation\PrivateDocumentationController;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->documentationRoot = sys_get_temp_dir().'/agelto-documentation-response-'.bin2hex(random_bytes(8));
    mkdir($this->documentationRoot.'/site/assets', recursive: true);
    file_put_contents($this->documentationRoot.'/site/index.html', '<script>bootstrap()</script><main>root sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/guide.html', '<main>nested sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/assets/app.css', 'body { color: black; }');
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'assets/app.css', 'mime' => 'text/css; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/app.css')],
        ['path' => 'guide.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/guide.html')],
        ['path' => 'index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/index.html')],
    ], flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    config(['documentation.root' => $this->documentationRoot]);
});

afterEach(function (): void {
    privateDocumentationResponseRemoveDirectory($this->documentationRoot);
});

test('response policy preserves manifest body mime headers and html csp', function (): void {
    config(['documentation.authorization_placeholder' => true]);

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

test('static assets share the security headers without html csp', function (): void {
    config(['documentation.authorization_placeholder' => true]);

    $response = $this->get('/admin/documentation/assets/app.css');

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('body { color: black; }')
        ->and($response->headers->get('Content-Type'))->toBe('text/css; charset=utf-8')
        ->and(privateDocumentationCacheDirectives($response->headers->get('Cache-Control')))->toBe(['no-store', 'private'])
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('nested manifest page is served and missing page has the uniform 404 surface', function (): void {
    config(['documentation.authorization_placeholder' => true]);

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
