<?php

declare(strict_types=1);

use App\Services\Llm\PromptCanonReader;

/**
 * Фальсифицируемость соответствия «корень ↔ document_type» (D32, Implementation Rule 6 P3.4):
 * файл сметной группы, временно положенный в корень ядра (и наоборот), обязан красить чтение
 * канона — без этого доменный корень остаётся соглашением, которое проверяет человек.
 */
function promptRootProbePath(bool $inDomainRoot): string
{
    $root = $inDomainRoot ? 'estimates/ai/prompts' : 'ai/prompts';

    return resource_path("{$root}/root-probe/0-root-probe.md");
}

function writePromptRootProbe(string $path, string $documentType, string $step): void
{
    @mkdir(dirname($path), recursive: true);

    file_put_contents($path, <<<MD
        ---
        document_type: {$documentType}
        key: root-probe
        step: {$step}
        version: 1
        priority: 0
        is_active: true
        ---

        Проба фальсифицируемости гейта «корень ↔ document_type».
        MD);
}

function removePromptRootProbe(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }

    if (is_dir(dirname($path))) {
        rmdir(dirname($path));
    }
}

test('файл сметной группы (document_type=estimate) в корне ядра ломает чтение канона', function (): void {
    $path = promptRootProbePath(inDomainRoot: false);

    try {
        writePromptRootProbe($path, documentType: 'estimate', step: 'extraction:rows');

        expect(fn () => app(PromptCanonReader::class)->read())->toThrow(RuntimeException::class);
    } finally {
        removePromptRootProbe($path);
    }
});

test('кросс-документный файл (document_type=any) в доменном корне ломает чтение канона', function (): void {
    $path = promptRootProbePath(inDomainRoot: true);

    try {
        writePromptRootProbe($path, documentType: 'any', step: 'global');

        expect(fn () => app(PromptCanonReader::class)->read())->toThrow(RuntimeException::class);
    } finally {
        removePromptRootProbe($path);
    }
});

test('файл на своём корне не ломает чтение канона — гейт не ложно-положительный', function (): void {
    $path = promptRootProbePath(inDomainRoot: true);

    try {
        writePromptRootProbe($path, documentType: 'estimate', step: 'extraction:rows');

        expect(fn () => app(PromptCanonReader::class)->read())->not->toThrow(RuntimeException::class);
    } finally {
        removePromptRootProbe($path);
    }
});
