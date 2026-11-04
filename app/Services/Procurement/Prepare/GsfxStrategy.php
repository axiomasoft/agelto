<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Actions\Procurement\Document\ExpandArchiveEntryAction;
use App\Actions\Procurement\Document\MarkDocumentUnprocessableAction;
use App\Dto\Procurement\Prepare\Archive\ArchiveBudget;
use App\Dto\Procurement\Prepare\Archive\ArchiveEntry;
use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument as GsfxDocumentDto;
use App\Enums\Procurement\Document\PreparationResult;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Services\Procurement\Prepare\Archive\ArchiveEntryValidator;
use App\Services\Procurement\Prepare\Archive\ArchiveExtractor;
use App\Services\Procurement\Prepare\Archive\ArchivePasswordProtectedException;
use App\Services\Procurement\Prepare\Archive\ArchiveUnsupportedException;
use App\Services\Procurement\Prepare\Archive\ArchiveValidationException;
use App\Services\Procurement\Prepare\Gsfx\GsfxUnreadableException;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\PipelineTemporaryFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * `.gsfx` — контейнер ГрандСметы: ZIP с `Properties.txt` (паспорт) + `Data.xml`
 * (позиции/ресурсы/разделы/начисления) в `windows-1251`. Производит ДВА представления —
 * `structured_xml` (вход детерминированного gold-парсера) и `text` (человекочитаемая
 * выжимка). Вложения контейнера (`Attachments/**`) используют общий bounded archive contract:
 * полный listing валидируется до фильтрации, а lineage/event/after-commit принадлежат Action.
 *
 * Битый ZIP, отсутствующий `Data.xml`, нечитаемый XML — `unprocessable(unreadable_format)`
 * (ожидаемый исход недоверенного входа, не программная ошибка). Превышение лимитов
 * `ai.prepare.archive.*` на вложениях — `unprocessable(zip_bomb)`, та же защита, что в
 * `ArchiveStrategy`.
 */
final readonly class GsfxStrategy implements PreparationStrategy
{
    public function __construct(
        private ExpandArchiveEntryAction $expandArchiveEntry,
        private RepresentationStoreRepository $representations,
        private RepresentationWriter $writer,
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private ArchiveExtractor $extractor,
        private ArchiveEntryValidator $entryValidator,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        if ($this->representations->find($version, RepresentationKind::StructuredXml) !== null) {
            return PreparationResult::ReadyForScreening;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $localZip = PipelineTemporaryFile::create(prefix: 'gsfx_', extension: 'zip');

        try {
            file_put_contents($localZip, $disk->get($version->storage_path));
            try {
                $budget = ArchiveBudget::fromConfig();
                // Полный hostile listing проходит общий gate ДО чтения Data.xml/Properties.txt.
                $entries = $this->entryValidator->validate(
                    entries: $this->extractor->listEntries(archivePath: $localZip, format: 'zip'),
                    budget: $budget,
                );
                $gsfxDocument = $this->readCore(archivePath: $localZip, entries: $entries, budget: $budget);
                $this->extractAttachments(document: $document, version: $version, localZip: $localZip, entries: $entries, budget: $budget);
            } catch (GsfxUnreadableException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchivePasswordProtectedException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::PasswordProtected, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveUnsupportedException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::ArchiveUnsupported, $exception->getMessage());

                return PreparationResult::Unprocessable;
            } catch (ArchiveValidationException $exception) {
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, $exception->getMessage());

                return PreparationResult::Unprocessable;
            }

            $this->storeRepresentations($version, $gsfxDocument, $disk, $diskName);
        } finally {
            @unlink($localZip);
        }

        return PreparationResult::ReadyForScreening;
    }

    /** @param list<ArchiveEntry> $entries */
    private function readCore(string $archivePath, array $entries, ArchiveBudget $budget): GsfxDocumentDto
    {
        $byPath = [];

        foreach ($entries as $entry) {
            $byPath[$entry->path] = $entry;
        }

        $dataEntry = $byPath['Data.xml'] ?? null;

        if (! $dataEntry instanceof ArchiveEntry || $dataEntry->isDirectory) {
            throw new GsfxUnreadableException('в контейнере отсутствует Data.xml');
        }

        $maxCoreBytes = $this->positiveIntConfig('ai.prepare.gsfx_max_core_xml_mb', 16) * 1024 * 1024;

        if ($dataEntry->declaredSize > $maxCoreBytes) {
            throw new ArchiveValidationException('GSFX Data.xml превышает отдельный decoded-byte лимит');
        }

        $workspace = PipelineTemporaryFile::createDirectory(prefix: 'gsfx_core_');

        try {
            $totalBytes = 0;
            $dataPath = $workspace.'/Data.xml';
            $this->extractor->extractEntry(
                archivePath: $archivePath,
                format: 'zip',
                entry: $dataEntry,
                targetPath: $dataPath,
                budget: $budget,
                totalBytes: $totalBytes,
            );

            $propertiesPath = $workspace.'/Properties.txt';
            $propertiesEntry = $byPath['Properties.txt'] ?? null;

            if ($propertiesEntry instanceof ArchiveEntry && ! $propertiesEntry->isDirectory) {
                $this->extractor->extractEntry(
                    archivePath: $archivePath,
                    format: 'zip',
                    entry: $propertiesEntry,
                    targetPath: $propertiesPath,
                    budget: $budget,
                    totalBytes: $totalBytes,
                );
            } else {
                file_put_contents($propertiesPath, '');
            }

            $outputPath = $workspace.'/parsed.json';
            $memoryLimit = config('ai.prepare.gsfx_parse_memory_limit', '256M');
            $timeout = $this->positiveIntConfig('ai.prepare.gsfx_parse_timeout_seconds', 60);

            if (! is_string($memoryLimit) || $memoryLimit === '') {
                throw new \UnexpectedValueException('ai.prepare.gsfx_parse_memory_limit must be a non-empty string.');
            }

            $result = Process::path(base_path())->timeout($timeout)->run([
                PHP_BINARY,
                '-d',
                "memory_limit={$memoryLimit}",
                'artisan',
                'procurement:parse-gsfx',
                $propertiesPath,
                $dataPath,
                $outputPath,
            ]);

            if ($result->failed() || ! is_file($outputPath)) {
                throw new GsfxUnreadableException('изолированный GSFX parser завершился с ошибкой: '.$result->errorOutput());
            }

            $payload = json_decode((string) file_get_contents($outputPath), true, flags: JSON_THROW_ON_ERROR);

            return GsfxDocumentDto::from($payload);
        } finally {
            PipelineTemporaryFile::deleteDirectory($workspace);
        }
    }

    private function positiveIntConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value < 1) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }

    private function storeRepresentations(DocumentVersion $version, GsfxDocumentDto $gsfxDocument, Filesystem $disk, string $diskName): void
    {
        $structuredXml = json_encode($gsfxDocument, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $text = $this->renderText($gsfxDocument);

        $positionsCount = array_sum(array_map(
            static fn ($chapter) => count($chapter->positions),
            $gsfxDocument->chapters,
        ));

        $meta = [
            'tool' => 'gsfx-reader',
            'program_version' => $gsfxDocument->passport->program_version,
            'positions' => $positionsCount,
            'unmapped' => $gsfxDocument->unmapped,
        ];

        $structuredXmlPath = $this->preparedPath($version, RepresentationKind::StructuredXml);
        $this->writer->write($disk, $version, RepresentationKind::StructuredXml, $structuredXmlPath, $structuredXml, [
            'storage_path' => $structuredXmlPath,
            'disk' => $diskName,
            'size_bytes' => strlen($structuredXml),
            'mime' => 'application/json',
            'meta' => $meta,
            'generated_at' => now(),
        ]);

        $textPath = $this->preparedPath($version, RepresentationKind::Text);
        $this->writer->write($disk, $version, RepresentationKind::Text, $textPath, $text, [
            'storage_path' => $textPath,
            'disk' => $diskName,
            'size_bytes' => strlen($text),
            'mime' => 'text/plain',
            'meta' => $meta,
            'generated_at' => now(),
        ]);
    }

    private function renderText(GsfxDocumentDto $gsfxDocument): string
    {
        $lines = [];

        $passport = $gsfxDocument->passport;
        $lines[] = trim(($passport->object_name ?? '').' ('.($passport->loc_num ?? '—').')');
        $lines[] = 'Нормативная база: '.($passport->normative_base ?? '—');
        $lines[] = 'Метод расчёта: '.($passport->calc_method ?? '—');
        $lines[] = 'Итого: '.($passport->total ?? '—');
        $lines[] = '';

        foreach ($gsfxDocument->chapters as $chapter) {
            $lines[] = '## '.$chapter->caption;

            foreach ($chapter->positions as $position) {
                $lines[] = sprintf(
                    '%s. %s [%s] — %s %s x %s = %s',
                    $position->number ?? '—',
                    $position->name,
                    $position->code ?? '—',
                    $position->quantity ?? '—',
                    $position->unit ?? '',
                    $position->price_curr ?? $position->price_base ?? '—',
                    $position->price_curr ?? $position->price_base ?? '—',
                );
            }
        }

        return implode("\n", $lines);
    }

    /** @param list<ArchiveEntry> $entries */
    private function extractAttachments(Document $document, DocumentVersion $version, string $localZip, array $entries, ArchiveBudget $budget): void
    {
        $attachments = array_values(array_filter(
            $entries,
            static fn (ArchiveEntry $entry): bool => ! $entry->isDirectory && str_starts_with($entry->path, 'Attachments/'),
        ));
        $extractPath = PipelineTemporaryFile::createDirectory(prefix: 'gsfx_extract_');

        try {
            $totalBytes = 0;
            foreach ($attachments as $entry) {
                $targetPath = $extractPath.'/'.$entry->path;
                $directory = dirname($targetPath);
                if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new \RuntimeException("не удалось создать временную директорию: {$directory}");
                }

                $this->extractor->extractEntry(
                    archivePath: $localZip,
                    format: 'zip',
                    entry: $entry,
                    targetPath: $targetPath,
                    budget: $budget,
                    totalBytes: $totalBytes,
                );
            }

            foreach ($attachments as $entry) {
                $this->expandArchiveEntry->execute(
                    parent: $document,
                    parentVersion: $version,
                    entryPath: $entry->path,
                    localPath: $extractPath.'/'.$entry->path,
                );
            }
        } finally {
            PipelineTemporaryFile::deleteDirectory($extractPath);
        }
    }

    private function preparedPath(DocumentVersion $version, RepresentationKind $kind): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.{$kind->value}.{$kind->extension()}";
    }

    private function markUnprocessable(Document $document, UnprocessableReason $reason, string $detail): void
    {
        $this->markUnprocessable->execute($document, $reason, $detail);
    }
}
