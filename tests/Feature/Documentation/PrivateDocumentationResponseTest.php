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
    mkdir($this->documentationRoot.'/site/assets/chunks', recursive: true);
    mkdir($this->documentationRoot.'/site/business', recursive: true);
    mkdir($this->documentationRoot.'/site/guide', recursive: true);
    file_put_contents($this->documentationRoot.'/site/index.html', '<script>bootstrap()</script><main>root sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/article.html', '<main>article sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/business/index.html', '<main>business directory sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/guide/index.html', '<main>directory sentinel</main>');
    file_put_contents($this->documentationRoot.'/site/assets/app.css', 'body { color: black; }');
    file_put_contents($this->documentationRoot.'/site/assets/app.js', 'window.documentationReady = true;');
    file_put_contents($this->documentationRoot.'/site/assets/chunks/@localSearchIndexroot.js', 'window.documentationSearchReady = true;');
    file_put_contents($this->documentationRoot.'/site/assets/portal.woff2', 'font sentinel');
    file_put_contents($this->documentationRoot.'/site/assets/search.json', '{"items":[]}');
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode([
        ['path' => 'article.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/article.html')],
        ['path' => 'assets/app.css', 'mime' => 'text/css; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/app.css')],
        ['path' => 'assets/app.js', 'mime' => 'text/javascript; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/app.js')],
        ['path' => 'assets/chunks/@localSearchIndexroot.js', 'mime' => 'text/javascript; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/chunks/@localSearchIndexroot.js')],
        ['path' => 'assets/portal.woff2', 'mime' => 'font/woff2', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/portal.woff2')],
        ['path' => 'assets/search.json', 'mime' => 'application/json; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/search.json')],
        ['path' => 'business/index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/business/index.html')],
        ['path' => 'guide/index.html', 'mime' => 'text/html; charset=utf-8', 'sha256' => hash_file('sha256', $this->documentationRoot.'/site/guide/index.html')],
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
    $response = $this->get('/documentation');

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
    $response = $this->get("/documentation/{$path}");

    expect(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe($contents)
        ->and($response->headers->get('Content-Type'))->toBe($mime)
        ->and(privateDocumentationCacheDirectives($response->headers->get('Cache-Control')))->toBe(['no-store', 'private'])
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
})->with([
    'CSS' => ['assets/app.css', 'text/css; charset=utf-8', 'body { color: black; }'],
    'JavaScript' => ['assets/app.js', 'text/javascript; charset=utf-8', 'window.documentationReady = true;'],
    'VitePress local search chunk' => ['assets/chunks/@localSearchIndexroot.js', 'text/javascript; charset=utf-8', 'window.documentationSearchReady = true;'],
    'JSON' => ['assets/search.json', 'application/json; charset=utf-8', '{"items":[]}'],
    'font' => ['assets/portal.woff2', 'font/woff2', 'font sentinel'],
]);

test('direct clean URLs resolve to manifest pages and missing page has the uniform 404 surface', function (): void {
    $article = $this->get('/documentation/article');

    expect(file_get_contents($article->baseResponse->getFile()->getPathname()))->toContain('article sentinel')
        ->and($article->headers->get('Content-Type'))->toBe('text/html; charset=utf-8');

    $directory = $this->get('/documentation/business/');

    expect(file_get_contents($directory->baseResponse->getFile()->getPathname()))->toContain('business directory sentinel')
        ->and($directory->headers->get('Content-Type'))->toBe('text/html; charset=utf-8');

    $this->get('/documentation/missing')
        ->assertNotFound()
        ->assertDontSee('root sentinel')
        ->assertDontSee('article sentinel');
});

test('active svg отсутствует в закрытом mime contract и не возвращает bytes', function (): void {
    file_put_contents($this->documentationRoot.'/site/assets/active.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>');
    $manifest = json_decode((string) file_get_contents($this->documentationRoot.'/manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $manifest[] = [
        'path' => 'assets/active.svg',
        'mime' => 'image/svg+xml',
        'sha256' => hash_file('sha256', $this->documentationRoot.'/site/assets/active.svg'),
    ];
    file_put_contents($this->documentationRoot.'/manifest.json', json_encode($manifest, flags: JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    $this->get('/documentation/assets/active.svg')
        ->assertNotFound()
        ->assertDontSee('alert(document.domain)');
});

test('only the declared GET HEAD documentation route exists and remains fail closed', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'documentation'));

    expect($routes)->toHaveCount(1)
        ->and($routes->first()->methods())->toBe(['GET', 'HEAD'])
        ->and($routes->first()->getActionName())->toBe(PrivateDocumentationController::class);

    actingAs(User::factory()->create());

    $this->get('/documentation')->assertForbidden();
    $this->call('HEAD', '/documentation')->assertForbidden();
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
