"""Изолированный bootstrap volume не сохраняет отключённые package providers."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[3]

BRIDGE = r"""
require $argv[1].'/vendor/autoload.php';
require $argv[1].'/tests/Support/ProcessCore/PackageDiscoveryCache.php';

class FixtureStableProvider extends Illuminate\Support\ServiceProvider {}

$root = $argv[2];
$files = new Illuminate\Filesystem\Filesystem;
$manifest = new Illuminate\Foundation\PackageManifest($files, $root, $root.'/bootstrap/cache/packages.php');
$before = $manifest->providers();
Tests\Support\ProcessCore\PackageDiscoveryCache::refresh($root);
$fresh = new Illuminate\Foundation\PackageManifest($files, $root, $root.'/bootstrap/cache/packages.php');
$providers = $fresh->providers();
$app = new Illuminate\Foundation\Application($root);
(new Illuminate\Foundation\ProviderRepository($app, $files, $root.'/bootstrap/cache/services.php'))->load($providers);
echo json_encode([
    'before' => $before,
    'after' => $providers,
    'services' => require $root.'/bootstrap/cache/services.php',
    'active_core_loaded' => $app->getProvider(Proxyma\Laravel\ProxymaServiceProvider::class) !== null,
], JSON_THROW_ON_ERROR);
"""


class PackageDiscoveryCacheTest(unittest.TestCase):
    def test_stale_discovery_is_rebuilt_before_provider_registration(self):
        core = "Proxyma\\Laravel\\ProxymaServiceProvider"
        ui = "Proxyma\\Filament\\ProxymaFilamentServiceProvider"
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            cache = root / "bootstrap/cache"
            cache.mkdir(parents=True)
            installed = root / "vendor/composer"
            installed.mkdir(parents=True)
            (root / "composer.json").write_text(json.dumps({
                "extra": {"laravel": {"dont-discover": [
                    "axiomasoft/proxyma", "axiomasoft/proxyma-filament"]}}}))
            packages = [( "axiomasoft/proxyma", core), ("axiomasoft/proxyma-filament", ui),
                        ("fixture/stable", "FixtureStableProvider")]
            (installed / "installed.json").write_text(json.dumps({
                "packages": [{"name": name, "extra": {"laravel": {"providers": [provider]}}}
                             for name, provider in packages]}))
            (cache / "packages.php").write_text(
                "<?php return ['axiomasoft/proxyma'=>['providers'=>[" + repr(core) + "]],"
                "'axiomasoft/proxyma-filament'=>['providers'=>[" + repr(ui) + "]]];")
            (cache / "services.php").write_text(
                "<?php return ['providers'=>[" + repr(core) + "], 'eager'=>[" + repr(core)
                + "], 'deferred'=>[], 'when'=>[]];")
            result = subprocess.run(
                [os.environ.get("PHP_BINARY", "php"), "-r", BRIDGE, str(ROOT), str(root)],
                capture_output=True, text=True, cwd=ROOT)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            actual = json.loads(result.stdout)
            self.assertIn(core, actual["before"])
            self.assertIn(ui, actual["before"])
            self.assertEqual(actual["after"], ["FixtureStableProvider"])
            self.assertEqual(actual["services"]["providers"], ["FixtureStableProvider"])
            self.assertFalse(actual["active_core_loaded"])


if __name__ == "__main__":
    unittest.main()
