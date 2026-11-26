<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Support\Architecture\PlatformSourceRules;

/** @return list<string> */
function retiredOutboundClasses(): array
{
    return [
        'App\\Services\\Procurement\\PlatformRequestBudget',
        'App\\Services\\Procurement\\Proxy\\ProxyCircuitBreaker',
        'App\\Services\\Procurement\\Proxy\\ProxyScoreCalculator',
        'App\\Services\\Procurement\\Proxy\\CrawlerProxySettingsResolver',
        'App\\Services\\Procurement\\Proxy\\LegacyProxyPasswordUpgrade',
        'App\\Repositories\\Procurement\\Proxy\\ProxyHealthStoreRepository',
        'App\\Repositories\\Procurement\\Crawler\\SourceCircuitRepository',
        'App\\Repositories\\Procurement\\Crawler\\FetchStoreRepository',
        'App\\Services\\Procurement\\FetchLogger',
        ...array_map(fn (string $name): string => 'App\\Services\\Procurement\\SourceControl\\'.$name,
            ['SourceCircuitBreaker', 'SourceFailureClassifier', 'SourceFailureReporter', 'SourceRunControl', 'SourceStageOperations', 'SuspendedRunResumer']),
    ];
}

function outboundExecutableSource(string $source): string
{
    return implode('', array_map(static fn (array|string $token): string => is_string($token) ? $token
        : (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]), token_get_all($source)));
}

test('удалённые сетевые writers отсутствуют и ни один app caller не зависит от их классов', function (): void {
    foreach (retiredOutboundClasses() as $class) {
        expect(is_file(app_path(str_replace('\\', '/', substr($class, 4)).'.php')))->toBeFalse($class);
    }
    $violations = [];
    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        foreach (PlatformSourceRules::inspect($file->getContents())['dependencies'] as $dependency) {
            if (in_array($dependency, retiredOutboundClasses(), true)) {
                $violations[] = $file->getRelativePathname().':'.$dependency;
            }
        }
    }
    expect($violations)->toBe([]);
    foreach ([
        '<?php use App\\Services\\Procurement\\SourceControl\\SourceRunControl as Hidden;',
        '<?php app("App\\\\Services\\\\Procurement\\\\SourceControl\\\\SourceRunControl");',
        '<?php new \\App\\Services\\Procurement\\SourceControl\\SourceRunControl;',
    ] as $bad) {
        expect(array_intersect(PlatformSourceRules::inspect($bad)['dependencies'], retiredOutboundClasses()))->not->toBeEmpty();
    }
});

test('acquisition callers не создают второй HTTP клиент и не владеют physical retry', function (): void {
    $violations = [];
    foreach (['Services/Procurement', 'Services/Platforms'] as $directory) {
        foreach (File::allFiles(app_path($directory)) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), ['HttpTransport.php', 'PinnedCurlHandler.php', 'AgeltoPhysicalHttpTransport.php'], true)) {
                continue;
            }
            $dependencies = PlatformSourceRules::inspect($file->getContents())['dependencies'];
            $source = outboundExecutableSource($file->getContents());
            if (array_intersect($dependencies, ['Illuminate\\Support\\Facades\\Http', 'Illuminate\\Http\\Client\\PendingRequest', 'GuzzleHttp\\Client']) !== []
                || preg_match('/(?:\\bcurl_(?:init|exec|multi_exec)\\s*\\(|->\\s*retry\\s*\\()/', $source)) {
                $violations[] = $file->getRelativePathname();
            }
        }
    }
    expect($violations)->toBe([]);
    $source = File::get(app_path('Services/Llm/Providers/CatalogOnlyProvider.php'));
    expect($source)->toContain('throw new UnsupportedConsumer')->not->toContain('PendingRequest', 'Prism\\', '->send(');
});
