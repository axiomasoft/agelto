<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\ProcessCore\LegacyOwnershipRules;

pest()->group('t32', 'p317');

test('T32 final production sources have no retired ownership readers tables or config gates', function (): void {
    $errors = [];
    foreach (['app', 'routes', 'config', 'database/migrations'] as $root) {
        foreach (Finder::create()->files()->in(base_path($root))->name('*.php') as $file) {
            $errors = [...$errors, ...LegacyOwnershipRules::violations($root.'/'.$file->getRelativePathname(), $file->getContents())];
        }
    }
    expect($errors)->toBe([])
        ->and(is_file(config_path('crawler.php')))->toBeFalse()
        ->and(is_file(base_path('tests/Unit/Architecture/platforms-dependency-baseline.php')))->toBeFalse();
});

test('T32 every retired ownership family has an executable negative fixture', function (): void {
    foreach (LegacyOwnershipRules::SYMBOLS as $symbol) {
        foreach (['<?php use '.$symbol.' as OldOwner;', '<?php $owner = '.var_export($symbol, true).';', '<?php new \\'.$symbol.';'] as $source) {
            expect(LegacyOwnershipRules::violations('fixture.php', $source))->toContain('fixture.php: legacy symbol '.$symbol);
        }
    }
    foreach (LegacyOwnershipRules::TABLES as $table) {
        expect(LegacyOwnershipRules::violations('fixture.php', '<?php DB::statement("CREATE TABLE '.$table.' (id int)");'))
            ->toContain('fixture.php: legacy table '.$table);
    }
    foreach (['crawler.downstream_enabled', 'crawler.delta_scan_enabled', 'crawler.health.enabled', 'crawler.requests_per_minute_per_proxy', 'ai.tools.ocr_timeout_seconds', 'ai.pipeline.lease_seconds', 'ai.retry.max_attempts'] as $key) {
        expect(LegacyOwnershipRules::violations('fixture.php', '<?php config('.var_export($key, true).');'))
            ->toContain('fixture.php: legacy config reader '.$key);
    }
    foreach (['PipelineJob', 'ScreeningJob', 'CrawlRunJob'] as $job) {
        expect(LegacyOwnershipRules::violations('fixture.php', '<?php namespace App\Jobs\Procurement; class '.$job.' {}'))->not->toBe([]);
    }
    expect(LegacyOwnershipRules::violations('fixture.php', '<?php use App\Models\Procurement\Concerns\HasLease;'))->not->toBe([]);
    expect(LegacyOwnershipRules::violations('fixture.php', '<?php use App\Services\Procurement\{OperationLogWriter};'))->not->toBe([]);
    expect(LegacyOwnershipRules::violations('app/Services/Procurement/Document/DownloadHandler.php',
        '<?php use App\Services\Procurement\Network\LegacyBridge;'))->not->toBe([]);
});

test('T32 comments lawful health fields feature options and new platform helpers are not legacy ownership', function (): void {
    expect(LegacyOwnershipRules::violations('fixture.php', <<<'PHP'
        <?php
        // config('crawler.downstream_enabled'); prc_fetches
        use App\Services\Platforms\Eis\Listing\EisWindowStability;
        config('provider.diagnostics_enabled');
        DB::table('prc_route_target_health')->update(['open_until' => null]);
        PHP))->toBe([]);
});
