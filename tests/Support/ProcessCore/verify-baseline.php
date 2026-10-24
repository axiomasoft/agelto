<?php

declare(strict_types=1);

use Tests\Support\ProcessCore\BaselineVerifier;

require __DIR__.'/BaselineVerifier.php';

$root = dirname(__DIR__, 3);
$manifest = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
$entries = [...$manifest['migrations'], ...$manifest['source_classes'], ...array_filter($manifest['files'], static fn (array $entry): bool => isset($entry['scenarios']))];
$violations = BaselineVerifier::violations(root: $root, entries: $entries);
$runtime = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/runtime-inputs.json'), associative: true, flags: JSON_THROW_ON_ERROR);
$runtimeEntries = array_map(static fn (array $entry): array => [
    'path' => $entry['source'], 'sha256' => $entry['sha256'],
], $runtime['files']);
$violations = [...$violations, ...BaselineVerifier::violations(root: $root, entries: $runtimeEntries)];

if (! is_file($root.'/docs/dev/runbooks/process-test-rig.md')) {
    $violations[] = 'docs/dev/runbooks/process-test-rig.md';
}

echo json_encode(['baseline_violations' => $violations], JSON_THROW_ON_ERROR).PHP_EOL;

exit($violations === [] ? 0 : 1);
