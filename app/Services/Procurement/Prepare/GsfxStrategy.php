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
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\Gsfx\GsfxUnreadableException;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * `.gsfx` — контейнер ГрандСметы (P3.3, D2): ZIP с `Properties.txt` (паспорт) + `Data.xml`
 * (позиции/ресурсы/разделы/начисления) в `windows-1251`. Производит ДВА представления —
 * `structured_xml` (вход детерминированного gold-парсера P5.1) и `text` (человекочитаемая
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
        private MarkDocumentUnprocessableAction $markUnprocessable,
        private ArchiveExtractor $extractor,
        private ArchiveEntryValidator $entryValidator,
        private GsfxReader $reader,
        private FileLocationResolver $locationResolver,
    ) {}

    public function process(Document $document, DocumentVersion $version): PreparationResult
    {
        if ($this->representations->find($version, RepresentationKind::StructuredXml) !== null) {
            return PreparationResult::ReadyForScreening;
        }

        $diskName = $this->locationResolver->currentDocumentsDisk();
        $disk = Storage::disk($diskName);
        $localZip = tempnam(sys_get_temp_dir(), 'gsfx_');

        try {
            file_put_contents($localZip, $disk->get($version->storage_path));

            $zip = new ZipArchive;
            if ($zip->open($localZip) !== true) {
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, 'не удалось открыть контейнер как ZIP');

                return PreparationResult::Unprocessable;
            }

            $dataXml = $zip->getFromName('Data.xml');
            if ($dataXml === false) {
                $zip->close();
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, 'в контейнере отсутствует Data.xml');

                return PreparationResult::Unprocessable;
            }

            $propertiesTxt = $zip->getFromName('Properties.txt');

            try {
                $gsfxDocument = $this->reader->parse((string) ($propertiesTxt !== false ? $propertiesTxt : ''), $dataXml);
            } catch (GsfxUnreadableException $e) {
                $zip->close();
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, $e->getMessage());

                return PreparationResult::Unprocessable;
            }

            $zip->close();

            try {
                $this->extractAttachments(document: $document, version: $version, localZip: $localZip);
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
        $disk->put($structuredXmlPath, $structuredXml);

        $this->representations->storeOrUpdate($version, RepresentationKind::StructuredXml, [
            'storage_path' => $structuredXmlPath,
            'disk' => $diskName,
            'size_bytes' => strlen($structuredXml),
            'mime' => 'application/json',
            'meta' => $meta,
            'generated_at' => now(),
        ]);

        $textPath = $this->preparedPath($version, RepresentationKind::Text);
        $disk->put($textPath, $text);

        $this->representations->storeOrUpdate($version, RepresentationKind::Text, [
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

    private function extractAttachments(Document $document, DocumentVersion $version, string $localZip): void
    {
        $budget = ArchiveBudget::fromConfig();
        $entries = $this->entryValidator->validate(
            entries: $this->extractor->listEntries(archivePath: $localZip, format: 'zip'),
            budget: $budget,
        );
        $attachments = array_values(array_filter(
            $entries,
            static fn (ArchiveEntry $entry): bool => ! $entry->isDirectory && str_starts_with($entry->path, 'Attachments/'),
        ));
        $tempFolder = 'tmp_extract/'.Str::uuid();
        $extractPath = storage_path('app/'.$tempFolder);

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
            File::deleteDirectory($extractPath);
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
