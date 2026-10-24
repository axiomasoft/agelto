<?php

declare(strict_types=1);

use Tests\Support\ProcessCore\PromptWorkspace;

require __DIR__.'/PromptWorkspace.php';

if (getenv('PROCESS_CORE_TEST') !== '1' || getenv('APP_ENV') !== 'testing'
    || dirname(__DIR__, 3) !== '/var/www/html' || ! is_file('/.dockerenv')) {
    throw new RuntimeException('Prompt workspace requires the isolated testing container.');
}

foreach (['core' => 'ai', 'estimates' => 'estimates/ai'] as $name => $path) {
    PromptWorkspace::refresh(
        source: '/process-core-inputs/prompts/'.$name,
        target: '/var/www/html/resources/'.$path.'/prompts',
    );
}

echo "Disposable prompt workspaces copied from readonly inputs.\n";
