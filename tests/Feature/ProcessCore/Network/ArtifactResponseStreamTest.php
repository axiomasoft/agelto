<?php

declare(strict_types=1);

use App\Dto\Procurement\Process\Outcomes\ArtifactRef;
use App\Exceptions\Procurement\Process\DependencyUnavailable;
use App\Services\Procurement\Network\ArtifactResponseStream;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcessCore\LedgerFixtures;

test('закрытие потока освобождает spool и повторное закрытие безопасно', function (): void {
    Storage::fake('local');
    $key = 'outbound-responses/close.bin';
    Storage::disk('local')->put($key, 'payload');
    $stream = new ArtifactResponseStream(new ArtifactRef('local', $key, LedgerFixtures::hash('payload')));

    expect($stream->read(3))->toBe('pay')->and(Storage::disk('local')->exists($key))->toBeTrue();
    $stream->close();
    $stream->close();
    expect(Storage::disk('local')->exists($key))->toBeFalse()->and($stream->detach())->toBeNull();
});

test('отсоединённый поток остаётся читаемым после удаления spool', function (): void {
    Storage::fake('local');
    $key = 'outbound-responses/detach.bin';
    Storage::disk('local')->put($key, 'payload');
    $stream = new ArtifactResponseStream(new ArtifactRef('local', $key, LedgerFixtures::hash('payload')));

    $resource = $stream->detach();
    expect(is_resource($resource))->toBeTrue()
        ->and(Storage::disk('local')->exists($key))->toBeFalse()
        ->and(stream_get_contents($resource))->toBe('payload')
        ->and($stream->detach())->toBeNull();
    fclose($resource);
});

test('отсутствующий spool отклоняется как недоступная зависимость', function (): void {
    Storage::fake('local');
    $ref = new ArtifactRef('local', 'outbound-responses/missing.bin', LedgerFixtures::hash('payload'));

    expect(fn () => new ArtifactResponseStream($ref))->toThrow(DependencyUnavailable::class, 'outbound_spool_unavailable');
});
