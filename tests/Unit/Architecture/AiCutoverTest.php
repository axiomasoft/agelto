<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\Support\Architecture\PlatformSourceRules;

pest()->group('t22', 't44', 'p313');

function aiBoundaryViolations(string $source): array
{
    return array_values(array_filter(PlatformSourceRules::inspect($source)['dependencies'],
        static fn (string $dependency): bool => str_starts_with($dependency, 'App\\') && str_contains($dependency, '\\Procurement\\')));
}

test('all Llm layers reject reverse Procurement dependencies including aliases and class strings', function (): void {
    $violations = [];
    foreach (['Services', 'Repositories', 'Dto', 'Models'] as $layer) {
        foreach (File::allFiles(app_path($layer.'/Llm')) as $file) {
            foreach (aiBoundaryViolations($file->getContents()) as $dependency) {
                $violations[] = $file->getRelativePathname().': '.$dependency;
            }
        }
    }
    expect($violations)->toBe([]);
    foreach ([
        '<?php use App\\Models\\Procurement\\Ai\\Analysis as Hidden;',
        '<?php new \\App\\Repositories\\Procurement\\Ai\\AnalysisRepository;',
        '<?php $class="App\\\\Services\\\\Procurement\\\\Extraction\\\\ExtractionHandler";',
    ] as $bad) {
        expect(aiBoundaryViolations($bad))->not->toBeEmpty();
    }
});

test('retired AI state owners jobs tables and ordinary provider bridge are absent', function (): void {
    foreach ([
        'Services/Llm/LlmCallRecorder.php', 'Services/Llm/LlmCacheManager.php', 'Services/Llm/ShadowDispatch.php',
        'Services/Llm/Providers/PrismGeminiProvider.php', 'Services/Procurement/Proxy/PoolOutboundProxy.php',
        'Services/Procurement/Analyzers/Estimate/ExtractionOrchestrator.php',
        'Repositories/Procurement/Ai/AnalysisRunStoreRepository.php', 'Repositories/Procurement/Ai/AnalysisStoreRepository.php',
        'Jobs/Procurement/ScreeningJob.php', 'Jobs/Procurement/ShadowAnalysisJob.php',
        'Jobs/Procurement/Analyzers/Estimate/EstimateExtractionJob.php', 'Models/Concerns/HasLease.php', 'Models/Concerns/HasErrorBlock.php',
    ] as $path) {
        expect(is_file(app_path($path)))->toBeFalse($path);
    }
    foreach ([app_path(), database_path('migrations')] as $root) {
        foreach (File::allFiles($root) as $file) {
            expect($file->getContents())->not->toMatch('/\b(?:prc_ai_analysis_runs|llm_calls)\b/');
        }
    }
    $handler = File::get(app_path('Services/Procurement/Extraction/ExtractionHandler.php'));
    expect($handler)->not->toContain('DB::', '->completeStructured(', '->dispatch(', 'Http::');
});
