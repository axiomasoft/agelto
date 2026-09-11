<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

const DOCS_INTEGRITY_ROOTS = [
    'docs',
];

const DOCS_INTEGRITY_AUDIENCES = ['dev', 'operator', 'owner', 'shared'];
const DOCS_INTEGRITY_TYPES = ['howto', 'reference', 'explanation', 'runbook', 'adr'];
const DOCS_INTEGRITY_MINIMUM_FILES = 73;
const DOCS_INTEGRITY_MINIMUM_LINKS = 158;

const DOCS_INTEGRITY_EXCLUDED_PATHS = [];

/**
 * @return list<string>
 */
function documentationMarkdownFiles(): array
{
    $roots = array_map(base_path(...), DOCS_INTEGRITY_ROOTS);
    $missingRoots = array_filter($roots, fn (string $root): bool => ! is_dir($root));

    expect($missingRoots)->toBe([], 'Не найдены корни документации: '.implode(', ', $missingRoots));

    return array_map(
        fn (SplFileInfo $file): string => (string) $file->getRealPath(),
        iterator_to_array(Finder::create()->files()->in($roots)->name('*.md')),
    );
}

function documentationRelativePath(string $path): string
{
    return str_replace(base_path().'/', '', $path);
}

function isExcludedDocumentationPath(string $relativePath): bool
{
    foreach (DOCS_INTEGRITY_EXCLUDED_PATHS as $excludedPath) {
        if ($relativePath === $excludedPath || str_starts_with($relativePath, $excludedPath.'/')) {
            return true;
        }
    }

    return false;
}

function resolveDocumentationLink(string $path, string $target): ?string
{
    $candidate = str_starts_with($target, '/')
        ? base_path('docs'.$target)
        : dirname($path).'/'.$target;
    $resolvedPath = realpath($candidate);

    if ($resolvedPath === false && pathinfo($candidate, PATHINFO_EXTENSION) === '') {
        $resolvedPath = realpath($candidate.'.md');
    }

    if ($resolvedPath === false) {
        return null;
    }

    return is_dir($resolvedPath)
        ? $resolvedPath.'/_index.md'
        : $resolvedPath;
}

/**
 * @return list<string>
 */
function linkedDocumentationPaths(string $path): array
{
    $contents = (string) file_get_contents($path);
    preg_match_all('/(?<!!)\[[^\]]*\]\(([^)\s]+)(?:\s+[^)]*)?\)/', $contents, $matches);
    $links = [];

    foreach ($matches[1] as $rawTarget) {
        $target = explode('#', $rawTarget, 2)[0];

        if ($target === '' || preg_match('/^(https?:|mailto:)/', $target)) {
            continue;
        }

        $resolvedPath = resolveDocumentationLink($path, $target);

        if ($resolvedPath !== null && is_file($resolvedPath) && str_ends_with($resolvedPath, '.md')) {
            $links[] = $resolvedPath;
        }
    }

    return array_values(array_unique($links));
}

test('каждый документ имеет допустимый frontmatter', function (): void {
    $files = documentationMarkdownFiles();
    $violations = [];

    foreach ($files as $path) {
        $relativePath = documentationRelativePath($path);

        if (isExcludedDocumentationPath($relativePath)) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        if (! preg_match('/\A---\R(?<frontmatter>.*?)\R---\R/s', $contents, $matches)) {
            $violations[] = sprintf('%s: отсутствует frontmatter.', $relativePath);

            continue;
        }

        $frontmatter = $matches['frontmatter'];

        if (! preg_match('/^audience:\s*(\S+)\s*$/m', $frontmatter, $audience)
            || ! in_array($audience[1], DOCS_INTEGRITY_AUDIENCES, strict: true)) {
            $violations[] = sprintf('%s: audience должен быть одним из %s.', $relativePath, implode(', ', DOCS_INTEGRITY_AUDIENCES));
        }

        if (! preg_match('/^type:\s*(\S+)\s*$/m', $frontmatter, $type)
            || ! in_array($type[1], DOCS_INTEGRITY_TYPES, strict: true)) {
            $violations[] = sprintf('%s: type должен быть одним из %s.', $relativePath, implode(', ', DOCS_INTEGRITY_TYPES));
        }

        if (str_starts_with($relativePath, 'docs/04_Operators/how-to/')
            && ! preg_match('/^last-reviewed:\s*\d{4}-\d{2}-\d{2}\s*$/m', $frontmatter)) {
            $violations[] = sprintf('%s: last-reviewed должен быть датой YYYY-MM-DD.', $relativePath);
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(DOCS_INTEGRITY_MINIMUM_FILES);
    expect($violations)->toBe([]);
});

test('каждая относительная markdown-ссылка ведёт в существующий файл', function (): void {
    $files = documentationMarkdownFiles();
    $violations = [];
    $links = 0;

    foreach ($files as $path) {
        $relativePath = documentationRelativePath($path);
        $contents = (string) file_get_contents($path);
        preg_match_all('/(?<!!)\[[^\]]*\]\(([^)\s]+)(?:\s+[^)]*)?\)/', $contents, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[1] as [$rawTarget, $offset]) {
            $links++;
            $target = explode('#', $rawTarget, 2)[0];

            if ($target === '' || preg_match('/^(https?:|mailto:)/', $target)) {
                continue;
            }

            $resolvedPath = resolveDocumentationLink($path, $target);

            if ($resolvedPath === null || ! file_exists($resolvedPath)) {
                $line = substr_count($contents, "\n", 0, $offset) + 1;
                $violations[] = sprintf('%s:%d: ссылка %s не разрешается в файл.', $relativePath, $line, $rawTarget);
            }
        }
    }

    expect(count($files))->toBeGreaterThanOrEqual(DOCS_INTEGRITY_MINIMUM_FILES);
    expect($links)->toBeGreaterThanOrEqual(DOCS_INTEGRITY_MINIMUM_LINKS);
    expect($violations)->toBe([]);
});

test('список временных исключений пуст', function (): void {
    expect(DOCS_INTEGRITY_EXCLUDED_PATHS)->toBe([]);
});

test('каждый документ достижим из стартовой страницы', function (): void {
    $files = documentationMarkdownFiles();
    $root = realpath(base_path('docs/index.md'));

    expect($root)->not->toBeFalse();

    $reachable = [$root => true];
    $queue = [$root];

    while ($queue !== []) {
        $path = array_shift($queue);

        foreach (linkedDocumentationPaths($path) as $linkedPath) {
            if (isset($reachable[$linkedPath])) {
                continue;
            }

            $reachable[$linkedPath] = true;
            $queue[] = $linkedPath;
        }
    }

    $orphans = array_values(array_filter(
        array_map(documentationRelativePath(...), $files),
        fn (string $relativePath): bool => ! isset($reachable[base_path($relativePath)]),
    ));

    expect(count($files))->toBeGreaterThanOrEqual(DOCS_INTEGRITY_MINIMUM_FILES);
    expect($orphans)->toBe([]);
});
