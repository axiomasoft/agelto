<?php

declare(strict_types=1);

use Tests\Support\ProcessCore\BaselineVerifier;
use Tests\Support\ProcessCore\SchemaInventory;

require __DIR__.'/BaselineVerifier.php';
require __DIR__.'/SchemaInventory.php';

$root = dirname(__DIR__, 3);
$manifest = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
$entries = [...$manifest['migrations'], ...$manifest['source_classes'], ...$manifest['contract_fixtures'], ...$manifest['files']];
$violations = BaselineVerifier::violations(root: $root, entries: $entries);
$runtime = json_decode(file_get_contents($root.'/tests/Fixtures/ProcessCore/runtime-inputs.json'), associative: true, flags: JSON_THROW_ON_ERROR);
$runtimeEntries = array_map(static fn (array $entry): array => [
    'path' => $entry['source'], 'sha256' => $entry['sha256'],
], $runtime['files']);
$violations = [...$violations, ...BaselineVerifier::violations(root: $root, entries: $runtimeEntries)];
$inventory = json_decode(file_get_contents(__DIR__.'/schema-inventory.json'), associative: true, flags: JSON_THROW_ON_ERROR);
$violations = [...$violations, ...SchemaInventory::violations(
    root: $root,
    inventory: $inventory,
    document: file_get_contents($root.'/docs/dev/process-schema.md'),
)];

if (! is_file($root.'/docs/dev/runbooks/process-test-rig.md')) {
    $violations[] = 'docs/dev/runbooks/process-test-rig.md';
}

echo json_encode(['baseline_violations' => $violations], JSON_THROW_ON_ERROR).PHP_EOL;

exit($violations === [] ? 0 : 1);
