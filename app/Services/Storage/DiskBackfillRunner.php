<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Repositories\Procurement\Ai\FixtureStoreRepository;
use App\Repositories\Procurement\Analyzers\Estimate\EstimateStoreRepository;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Материализация записывает фактический disk вместо null до переключения конфигурации.
 * Перенос копирует объект потоком и обновляет запись после совпадения sha256 и размера
 * на приёмнике; ETag не заменяет проверку содержимого. Запись делегируется repositories.
 *
 * Генераторы documentRows/fixtureRows/estimateRows приводят носители к общей строке
 * {id, disk, path, hash}; алгоритм переноса не ветвится по типам Eloquent models.
 */
final readonly class DiskBackfillRunner
{
    public const array CARRIERS = ['document-versions', 'fixtures', 'estimate-raw-results'];

    public function __construct(
        private FileLocationResolver $resolver,
        private DocumentStoreRepository $documents,
        private FixtureStoreRepository $fixtures,
        private EstimateStoreRepository $estimates,
    ) {}

    /**
     * Объекты не трогает вообще: для строк с `disk IS NULL` проставляет диск, который ПРЯМО
     * СЕЙЧАС вернул бы резолвер. Обязана запускаться ДО флипа конфига — после флипа проставит
     * новое имя историческим строкам, чьи объекты лежат на старом диске.
     *
     * @return array{mode: 'materialize', processed: int, materialized: int, cursor: int|null}
     */
    public function materialize(string $carrier, int $fromId, bool $dryRun): array
    {
        $processed = 0;
        $materialized = 0;
        $cursor = null;
        $currentDisk = $this->currentDisk($carrier);

        foreach ($this->rows($carrier, $fromId) as $row) {
            $processed++;
            $cursor = $row['id'];

            if ($row['disk'] !== null) {
                continue;
            }

            $materialized++;

            if (! $dryRun) {
                $this->markDisk($carrier, $row['id'], $currentDisk);
            }
        }

        return ['mode' => 'materialize', 'processed' => $processed, 'materialized' => $materialized, 'cursor' => $cursor];
    }

    /**
     * Порядок на строку неизменен: заявить → если диск записи уже совпадает
     * с целью и хэш сходится, пропустить → если объект на приёмнике уже есть, сравнить хэш/размер
     * → иначе скопировать потоком → сравнить sha256/размер → ТОЛЬКО ПОТОМ обновить запись.
     * Расхождение не валит прогон: строка попадает в отчёт, прогон продолжается. Источник не
     * удаляется никогда.
     *
     * @return array{mode: 'transfer', processed: int, copied: int, skipped: int, discrepancies: list<array{id: int, reason: string}>, cursor: int|null}
     */
    public function transfer(string $carrier, string $target, int $fromId, bool $dryRun): array
    {
        if ($carrier === 'document-versions') {
            throw new RuntimeException('Canonical document artifact locations are immutable; transfer needs a new verified artifact policy.');
        }
        $processed = 0;
        $copied = 0;
        $skipped = 0;
        $discrepancies = [];
        $cursor = null;

        foreach ($this->rows($carrier, $fromId) as $row) {
            $processed++;
            $cursor = $row['id'];

            $sourceDisk = $row['disk'] ?? $this->currentDisk($carrier);
            $path = $row['path'];

            if ($sourceDisk === $target) {
                $stored = $row['hash'];

                if ($stored === null || $this->sha256($target, $path) === $stored) {
                    $skipped++;
                } else {
                    $discrepancies[] = ['id' => $row['id'], 'reason' => 'уже на целевом диске: хэш не совпал'];
                }

                continue;
            }

            $reference = $row['hash'] ?? $this->sha256($sourceDisk, $path);

            if (Storage::disk($target)->exists($path)) {
                if ($this->matches($target, $path, $sourceDisk, $reference)) {
                    if (! $dryRun) {
                        $this->markDisk($carrier, $row['id'], $target);
                    }
                    $copied++;
                } else {
                    $discrepancies[] = ['id' => $row['id'], 'reason' => 'объект уже на приёмнике: хэш/размер не совпал'];
                }

                continue;
            }

            if ($dryRun) {
                $copied++;

                continue;
            }

            $this->streamCopy($sourceDisk, $target, $path);

            if ($this->matches($target, $path, $sourceDisk, $reference)) {
                $this->markDisk($carrier, $row['id'], $target);
                $copied++;
            } else {
                $discrepancies[] = ['id' => $row['id'], 'reason' => 'расхождение sha256/размера после копирования'];
            }
        }

        return ['mode' => 'transfer', 'processed' => $processed, 'copied' => $copied, 'skipped' => $skipped, 'discrepancies' => $discrepancies, 'cursor' => $cursor];
    }

    /**
     * @return iterable<array{id: int, disk: ?string, path: string, hash: ?string}>
     */
    private function rows(string $carrier, int $fromId): iterable
    {
        return match ($carrier) {
            'document-versions' => $this->documentRows($fromId),
            'fixtures' => $this->fixtureRows($fromId),
            'estimate-raw-results' => $this->estimateRows($fromId),
            default => throw new RuntimeException("Неизвестный носитель backfill'а: {$carrier}"),
        };
    }

    /**
     * @return iterable<array{id: int, disk: ?string, path: string, hash: ?string}>
     */
    private function documentRows(int $fromId): iterable
    {
        foreach ($this->documents->versionsFromId($fromId) as $version) {
            yield ['id' => $version->id, 'disk' => $version->disk, 'path' => $version->storage_path, 'hash' => $version->content_hash];
        }
    }

    /**
     * @return iterable<array{id: int, disk: ?string, path: string, hash: ?string}>
     */
    private function fixtureRows(int $fromId): iterable
    {
        foreach ($this->fixtures->fromId($fromId) as $fixture) {
            yield ['id' => $fixture->id, 'disk' => $fixture->disk, 'path' => $fixture->storage_path, 'hash' => $fixture->sha256];
        }
    }

    /**
     * У estimate raw results нет колонки hash. При hash=null caller вычисляет эталонный
     * хеш потоком из исходного объекта до копирования.
     *
     * @return iterable<array{id: int, disk: ?string, path: string, hash: ?string}>
     */
    private function estimateRows(int $fromId): iterable
    {
        foreach ($this->estimates->withRawResultRefFromId($fromId) as $estimate) {
            yield ['id' => $estimate->id, 'disk' => $estimate->raw_result_disk, 'path' => (string) $estimate->raw_result_ref, 'hash' => null];
        }
    }

    private function currentDisk(string $carrier): string
    {
        return match ($carrier) {
            'document-versions' => $this->resolver->currentDocumentsDisk(),
            'fixtures' => $this->resolver->currentFixturesDisk(),
            'estimate-raw-results' => $this->resolver->currentRawDisk(),
            default => throw new RuntimeException("Неизвестный носитель backfill'а: {$carrier}"),
        };
    }

    private function markDisk(string $carrier, int $id, string $disk): void
    {
        match ($carrier) {
            'document-versions' => throw new RuntimeException('Canonical version disk cannot be materialized or overwritten.'),
            'fixtures' => $this->fixtures->updateDisk($id, $disk),
            'estimate-raw-results' => $this->estimates->updateRawResultDisk($id, $disk),
            default => throw new RuntimeException("Неизвестный носитель backfill'а: {$carrier}"),
        };
    }

    private function matches(string $targetDisk, string $path, string $sourceDisk, string $reference): bool
    {
        return $this->sha256($targetDisk, $path) === $reference
            && Storage::disk($targetDisk)->size($path) === Storage::disk($sourceDisk)->size($path);
    }

    private function sha256(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Не удалось открыть поток {$disk}:{$path} для хэширования");
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private function streamCopy(string $sourceDisk, string $targetDisk, string $path): void
    {
        $stream = Storage::disk($sourceDisk)->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Не удалось открыть поток {$sourceDisk}:{$path} для копирования");
        }

        Storage::disk($targetDisk)->writeStream($path, $stream);
        fclose($stream);
    }
}
