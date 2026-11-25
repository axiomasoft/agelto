<?php

declare(strict_types=1);

namespace Tests\Support\ProcessCore;

use App\Support\Files\PipelineTemporaryFile;

/** Runs deterministic tool stand-ins through the real converter supervisor. */
final class FakePrepareTools
{
    private string $directory;

    private string $log;

    public function __construct()
    {
        $this->directory = PipelineTemporaryFile::createDirectory(prefix: 'fake_tools_');
        // Shared memory is available to supervised tools for local IPC.
        $this->log = '/dev/shm/'.basename($this->directory).'.jsonl';
        touch($this->log);
        chmod($this->log, 0600);
    }

    public static function instance(): self
    {
        if (! app()->bound(self::class)) {
            app()->instance(self::class, new self);
        }

        return app(self::class);
    }

    public static function cleanup(): void
    {
        if (app()->bound(self::class)) {
            $tools = app(self::class);
            unlink($tools->log);
            PipelineTemporaryFile::deleteDirectory($tools->directory);
            app()->forgetInstance(self::class);
        }
    }

    /** @param array<string, mixed> $data */
    public function configure(string $key, string $code, array $data = []): void
    {
        $binary = $this->directory.'/'.$key;
        $startup = <<<'PY'
import base64
import json
import os
import subprocess
import sys
from pathlib import Path
PY;
        $input = base64_encode(json_encode($data, flags: JSON_THROW_ON_ERROR));
        file_put_contents($binary, <<<PY
#!/usr/bin/env python3
{$startup}
args = sys.argv[1:]
data = json.loads(base64.b64decode('{$input}'))
with open('{$this->log}', 'a') as log:
    log.write(json.dumps([sys.argv[0], *args]) + "\\n")
{$code}

PY);
        chmod($binary, 0700);
        config(["ai.tools.{$key}" => $binary]);
    }

    /** @return list<list<string>> */
    public function calls(string $key): array
    {
        $calls = [];
        foreach (file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $command = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (basename($command[0]) === $key) {
                $calls[] = array_slice($command, 1);
            }
        }

        return $calls;
    }

    /** @param list<string> $pdfContents */
    public function soffice(bool $succeeds = true, array $pdfContents = ['%PDF-fake']): void
    {
        $this->configure(key: 'soffice_bin', code: <<<'PY'
if args == ['--version']:
    print('LibreOffice 7.6.4.1 30(Build:4)\nBuild ID: abc123')
elif not data['succeeds']:
    sys.exit('soffice: crashed on convert')
elif data['pdfs']:
    with open(data['log']) as log:
        index = sum('--convert-to' in json.loads(line) for line in log) - 1
    output = Path(args[args.index('--outdir') + 1]) / Path(args[-1]).with_suffix('.pdf').name
    output.write_text(data['pdfs'][min(index, len(data['pdfs']) - 1)])
PY, data: ['succeeds' => $succeeds, 'pdfs' => $pdfContents, 'log' => $this->log]);
    }
}
