<?php

declare(strict_types=1);

use Composer\Semver\Semver;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$lock = json_decode(file_get_contents($root.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
$incompatible = [];
foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
    if (! Semver::satisfies(PHP_VERSION, $package['require']['php'] ?? '*')
        || (isset($package['conflict']['php']) && Semver::satisfies(PHP_VERSION, $package['conflict']['php']))) {
        $incompatible[] = $package['name'];
    }
}

if ($incompatible !== ['mpdf/mpdf']) {
    throw new RuntimeException('Recheck the temporary php+ waiver. Incompatible packages: '.json_encode($incompatible, JSON_THROW_ON_ERROR));
}
