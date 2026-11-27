<?php

declare(strict_types=1);
use App\Services\Procurement\Prepare\Tools\BoundedToolProcess;
use Tests\Support\ProcessCore\ToolContext;

require __DIR__.'/../../../vendor/autoload.php';
$directory = $argv[1];
(new BoundedToolProcess)->run(
    ['python3', __DIR__.'/tool-tree.py', $directory], $directory, ToolContext::make(), hrtime(true) / 1e9 + 1);
