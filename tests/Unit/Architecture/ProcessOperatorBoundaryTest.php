<?php

declare(strict_types=1);

// Operator surface (observability#s04): Filament и Pulse читают Process-ядро, но не пишут в него —
// мутации идут только через ProcessOperatorCommands и его Actions с version fence и audit.

/** @return list<string> */
function processSurfaceFiles(): array
{
    $files = [];
    foreach (['app/Filament', 'app/Pulse'] as $root) {
        if (! is_dir(base_path($root))) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

test('Filament и Pulse не пишут в Process ledger напрямую и не берут writer-репозитории', function (): void {
    $writers = 'Repositories\\\\Procurement\\\\Process\\\\(Control|Incident|WorkMutation|WorkStore|WorkFinalization|Transition|Outbox|Admission|Recovery)Repository'
        .'|Actions\\\\Procurement\\\\Process\\\\(CancelWork|CancelRun|RetryHandlerFaultedWork|SetScopeMode|AcknowledgeIncident)\\b';
    $violations = [];
    foreach (processSurfaceFiles() as $path) {
        $code = (string) file_get_contents($path);
        $touchesProcess = str_contains($code, 'Models\\Procurement\\Process') || str_contains($code, 'Services\\Procurement\\Process') || str_contains($code, "'prc_process_") || str_contains($code, "'prc_control_");
        if (preg_match('/'.$writers.'/', $code) === 1) {
            $violations[] = str_replace(base_path().'/', '', $path).': writer repository/action вместо ProcessOperatorCommands';
        }
        if ($touchesProcess && preg_match('/(DB::(update|insert|delete|statement)\(|->(update|delete|forceDelete|save|insert|upsert)\()/', $code) === 1) {
            $violations[] = str_replace(base_path().'/', '', $path).': прямая запись рядом с Process моделями';
        }
    }

    expect($violations)->toBe([]);
});
