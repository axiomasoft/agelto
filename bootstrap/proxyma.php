<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;
use Proxyma\Application\Execution\ProxyResolver;
use Proxyma\Infrastructure\Http\DnsResolver;
use Proxyma\Infrastructure\Security\SecretRedactor;

// Local fixes for the pinned Proxyma release. Verify the upstream sources
// before replacing their class map entries, so a package upgrade is explicit.
(static function (): void {
    $loader = ClassLoader::getRegisteredLoaders()[dirname(__DIR__).'/vendor'] ?? null;
    if ($loader === null) {
        throw new LogicException('The Composer loader is required to load the Proxyma fixes.');
    }

    $fixes = [
        ProxyResolver::class => ['ProxyResolver.php', 'e9a8d1ee60c8fb71992cfba019b38b351da69e4c543e84709c94c297b68c4ec8'],
        DnsResolver::class => ['DnsResolver.php', 'f0deeb50b149be29cb02197c0fe51a05767ca3702376ee1457f4961f7ed3ca3e'],
        SecretRedactor::class => ['SecretRedactor.php', '8d1579919eac74a26629f68d9b3732debe9a7dd20c12b549b07c643dd9084697'],
    ];

    $classMap = [];
    foreach ($fixes as $class => [$file, $expectedHash]) {
        $upstream = $loader->findFile($class);
        if (class_exists($class, false) || $upstream === false || hash_file('sha256', $upstream) !== $expectedHash) {
            throw new LogicException("The installed Proxyma source differs from the expected source for {$class}.");
        }

        $classMap[$class] = __DIR__.'/proxyma/'.$file;
    }

    $loader->addClassMap($classMap);
})();
