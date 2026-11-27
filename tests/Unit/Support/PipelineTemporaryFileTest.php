<?php

declare(strict_types=1);

use App\Support\Files\PipelineTemporaryFile;

test('temporary file создаётся сразу с нужным расширением без base-file утечки', function (): void {
    $before = glob(sys_get_temp_dir().'/agelto-pipeline/f10_*') ?: [];
    $path = PipelineTemporaryFile::create(prefix: 'f10_', extension: 'pdf');

    try {
        expect($path)->toEndWith('.pdf')
            ->and(is_file($path))->toBeTrue();

        $after = glob(sys_get_temp_dir().'/agelto-pipeline/f10_*') ?: [];
        expect(array_values(array_diff($after, $before)))->toBe([$path]);
    } finally {
        @unlink($path);
    }
});

test('stale sweeper удаляет файлы и workspaces старше безопасного окна', function (): void {
    $old = PipelineTemporaryFile::create(prefix: 'f10_old_', extension: 'tmp');
    $fresh = PipelineTemporaryFile::create(prefix: 'f10_fresh_', extension: 'tmp');
    $oldWorkspace = PipelineTemporaryFile::createDirectory(prefix: 'f10_old_workspace_');
    $freshWorkspace = PipelineTemporaryFile::createDirectory(prefix: 'f10_fresh_workspace_');
    file_put_contents($oldWorkspace.'/orphan.bin', 'partial');
    touch($old, time() - 3600);
    touch($oldWorkspace, time() - 3600);

    try {
        expect(PipelineTemporaryFile::purgeOlderThan(seconds: 60))->toBeGreaterThanOrEqual(1)
            ->and(file_exists($old))->toBeFalse()
            ->and(file_exists($oldWorkspace))->toBeFalse()
            ->and(file_exists($fresh))->toBeTrue()
            ->and(file_exists($freshWorkspace))->toBeTrue();
    } finally {
        @unlink($old);
        @unlink($fresh);
        if (is_dir($oldWorkspace)) {
            PipelineTemporaryFile::deleteDirectory($oldWorkspace);
        }
        PipelineTemporaryFile::deleteDirectory($freshWorkspace);
    }
});
