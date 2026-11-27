<?php

declare(strict_types=1);

use Tests\Support\ProcessCore\SchemaInventory;

require_once __DIR__.'/SchemaInventory.php';

$root = dirname(__DIR__, 3);
$inventory = json_decode(file_get_contents(__DIR__.'/schema-inventory.json'), associative: true, flags: JSON_THROW_ON_ERROR);

if (($argv[1] ?? null) === '--render') {
    echo SchemaInventory::render(inventory: $inventory);
    exit(0);
}

if (count($argv) > 1 && $argv !== [$argv[0], '--catalog-stdin']) {
    fwrite(STDERR, "usage: verify-schema.php [--render|--catalog-stdin]\n");
    exit(64);
}

$actual = ($argv[1] ?? null) === '--catalog-stdin'
    ? json_decode(stream_get_contents(STDIN), associative: true, flags: JSON_THROW_ON_ERROR)
    : null;
$violations = SchemaInventory::violations(
    root: $root,
    inventory: $inventory,
    document: file_get_contents($root.'/docs/dev/process-schema.md'),
    actual: $actual,
);
echo json_encode(['schema_inventory_violations' => $violations], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;
exit($violations === [] ? 0 : 1);
