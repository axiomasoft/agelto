<?php

declare(strict_types=1);
use App\Dto\Procurement\Document\PrepareRange;
use App\Enums\Procurement\Document\PageTextStatus;
use App\Exceptions\Procurement\Prepare\ToolFailed;
use App\Exceptions\Procurement\Process\LeaseLost;
use App\Services\Procurement\Prepare\BoundedToolProcess;
use App\Services\Procurement\Prepare\PageBatchOcr;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\ProcessCore\ToolContext;

pest()->group('pdf-preparation-coverage', 'execution-deadlines');
beforeEach(function (): void {
    $this->dir = storage_path('app/testing/tool-'.bin2hex(random_bytes(6)));
    mkdir($this->dir, 0700, true);
});
afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

test('реальный OCR сохраняет native и scanned текст, пустая skip страница exit0 остаётся unrecognized', function (): void {
    $tools = app(PageBatchOcr::class);
    $ctx = ToolContext::make();
    $source = base_path('tests/Fixtures/ProcessCore/Documents/mixed-native-scan-blank.pdf');
    $deadline = hrtime(true) / 1e9 + 45;
    expect($tools->pageCount($source, $this->dir, $ctx, $deadline))->toBe(3);
    $batch = $tools->batch($source, $this->dir, new PrepareRange(0, 3), $ctx, $deadline);
    expect($batch->anchoredText)->toContain('[page:1]', 'NATIVE ANCHOR ONE', '[page:2]', 'SCANNED ANCHOR TWO', '[page:3]')
        ->and(array_map(fn ($p) => $p->status, $batch->pages))->toBe([PageTextStatus::NativeText, PageTextStatus::OcrText, PageTextStatus::Unrecognized])
        ->and(glob($this->dir.'/*.active'))->toBe([]);
    $sidecars = glob($this->dir.'/*.sidecar');
    expect(file_get_contents($sidecars[0]))->not->toContain('NATIVE ANCHOR ONE');
});

test('stalled tool и TERM resistant descendants ограничены hard deadline, вся группа stopped и reaped', function (): void {
    $started = hrtime(true);
    expect(fn () => (new BoundedToolProcess)->run(['python3', base_path('tests/Support/ProcessCore/tool-tree.py'), $this->dir], $this->dir, ToolContext::make(), hrtime(true) / 1e9 + .3))->toThrow(ToolFailed::class);
    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(5)->and(glob($this->dir.'/*.active'))->toBe([]);
    foreach (explode(' ', file_get_contents($this->dir.'/pids')) as $pid) {
        expect(posix_kill((int) $pid, 0))->toBeFalse();
    }
    $receipts = array_filter(glob($this->dir.'/tool-*'), fn ($p) => ! str_contains(basename($p), '.'));
    $receipt = json_decode(file_get_contents(array_values($receipts)[0]), true);
    expect($receipt['stopped'])->toBeTrue()->and($receipt['reason'])->toBe('page_timeout');
});

test('lease loss прерывает tool и подтверждает cleanup до возврата typed исключения', function (): void {
    expect(fn () => (new BoundedToolProcess)->run(['python3', base_path('tests/Support/ProcessCore/tool-tree.py'), $this->dir], $this->dir, ToolContext::make(true), hrtime(true) / 1e9 + 20))->toThrow(LeaseLost::class);
    expect(glob($this->dir.'/*.active'))->toBe([]);
    foreach (explode(' ', file_get_contents($this->dir.'/pids')) as $pid) {
        expect(posix_kill((int) $pid, 0))->toBeFalse();
    }
});

test('SIGKILL PHP worker оставляет independent wrapper который завершает orphan tool group и пишет cleanup receipt', function (): void {
    $worker = new Process(['php', base_path('tests/Support/ProcessCore/tool-worker.php'), $this->dir]);
    $worker->start();
    $end = hrtime(true) + 3_000_000_000;
    while (! is_file($this->dir.'/pids') && hrtime(true) < $end) {
        usleep(20000);
    }
    expect(is_file($this->dir.'/pids'))->toBeTrue();
    $worker->signal(SIGKILL);
    $worker->wait();
    // Worker death alone is not cleanup evidence: active marker remains and tmp cannot be released.
    expect(glob($this->dir.'/*.active'))->not->toBe([]);
    $end = hrtime(true) + 5_000_000_000;
    while (glob($this->dir.'/*.active') !== [] && hrtime(true) < $end) {
        usleep(20000);
    }
    expect(glob($this->dir.'/*.active'))->toBe([]);
    foreach (explode(' ', file_get_contents($this->dir.'/pids')) as $pid) {
        expect(posix_kill((int) $pid, 0))->toBeFalse();
    }
});

test('реальный tesseract timeout при exit0 не превращает skipped scanned page в пустую complete', function (): void {
    config()->set('documents.prepare.tesseract_timeout_seconds', 0);
    $tools = app(PageBatchOcr::class);
    $ctx = ToolContext::make();
    $batch = $tools->batch(base_path('tests/Fixtures/ProcessCore/Documents/mixed-native-scan-blank.pdf'), $this->dir, new PrepareRange(0, 3), $ctx, hrtime(true) / 1e9 + 45);
    expect($batch->pages[0]->status)->toBe(PageTextStatus::NativeText)
        ->and($batch->pages[1]->status)->toBe(PageTextStatus::Unrecognized)
        ->and($batch->anchoredText)->toContain('[page:1]', 'NATIVE ANCHOR ONE', '[page:2]', '[page:3]');
});
