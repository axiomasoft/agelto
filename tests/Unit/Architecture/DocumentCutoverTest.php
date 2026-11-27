<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/** @return list<string> */
function documentCutoverViolations(string $source): array
{
    $retired = ['PipelineDispatcher', 'PipelineRecoveryReconciler', 'DocumentRevalidationSelector',
        'DocumentDownloader', 'DocumentIndexer', 'DownloadDocumentJob', 'PrepareDocumentJob', 'RevalidateDocumentJob'];
    $errors = [];
    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING], true)) {
            continue;
        }
        $text = str_replace('\\\\', '\\', $token[1]);
        foreach ($retired as $name) {
            if (preg_match('/(?:^|[\\\\\'"])'.preg_quote($name, '/').'(?:$|[\'"])/', $text)) {
                $errors[] = 'retired_owner:'.$name;
            }
        }
        if (str_contains($text, 'Tests\\Support\\ProcessCore\\Historical\\')) {
            $errors[] = 'historical_harness_in_runtime';
        }
        if (str_contains(strtolower($text), 'downstream_enabled')) {
            $errors[] = 'acquisition_document_coupling';
        }
    }

    return array_values(array_unique($errors));
}

test('document cutover не оставляет ordinary writers jobs и downstream control coupling', function (): void {
    $errors = [];
    foreach (Finder::create()->files()->in([app_path(), config_path()])->name('*.php') as $file) {
        foreach (documentCutoverViolations($file->getContents()) as $error) {
            $errors[] = $file->getRelativePathname().':'.$error;
        }
    }
    expect($errors)->toBe([]);
    foreach (['PipelineDispatcher', 'PipelineRecoveryReconciler', 'DocumentRevalidationSelector', 'DocumentDownloader', 'DocumentIndexer'] as $name) {
        expect(is_file(app_path('Services/Procurement/'.$name.'.php')))->toBeFalse();
    }
});

test('document cutover guard обнаруживает forbidden import FQCN string и config read', function (): void {
    expect(documentCutoverViolations('<?php use App\\Services\\Procurement\\PipelineDispatcher;'))->toContain('retired_owner:PipelineDispatcher');
    expect(documentCutoverViolations('<?php new \\App\\Jobs\\Procurement\\PrepareDocumentJob;'))->toContain('retired_owner:PrepareDocumentJob');
    expect(documentCutoverViolations('<?php config("crawler.downstream_enabled");'))->toContain('acquisition_document_coupling');
    expect(documentCutoverViolations('<?php use Tests\\Support\\ProcessCore\\Historical\\AnalysisRecoveryHarness;'))->toContain('historical_harness_in_runtime');
    expect(documentCutoverViolations('<?php /* PipelineDispatcher */ $purpose="documents";'))->toBe([]);
});
