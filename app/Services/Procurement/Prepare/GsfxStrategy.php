<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Dto\Procurement\Prepare\Gsfx\GsfxDocument as GsfxDocumentDto;
use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Enums\Procurement\ProcurementEventType;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use App\Repositories\Procurement\ProcurementStoreRepository;
use App\Services\Procurement\Prepare\Gsfx\GsfxReader;
use App\Services\Procurement\Prepare\Gsfx\GsfxUnreadableException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * `.gsfx` — контейнер ГрандСметы (P3.3, D2): ZIP с `Properties.txt` (паспорт) + `Data.xml`
 * (позиции/ресурсы/разделы/начисления) в `windows-1251`. Производит ДВА представления —
 * `structured_xml` (вход детерминированного gold-парсера P5.1) и `text` (человекочитаемая
 * выжимка); вложения контейнера (`Attachments/**`) становятся дочерними документами тем же
 * приёмом, что `ArchiveStrategy::extractChildren()` — приватность метода не позволяет переиспользо-
 * вать его напрямую, приём воспроизведён здесь для `Attachments/**`.
 *
 * Битый ZIP, отсутствующий `Data.xml`, нечитаемый XML — `unprocessable(unreadable_format)`
 * (ожидаемый исход недоверенного входа, не программная ошибка). Превышение лимитов
 * `ai.prepare.archive.*` на вложениях — `unprocessable(zip_bomb)`, та же защита, что в
 * `ArchiveStrategy`.
 */
final readonly class GsfxStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private DocumentStoreRepository $storeRepository,
        private ProcurementStoreRepository $procurementStore,
        private GsfxReader $reader,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        if ($version->extension !== 'gsfx') {
            return false;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $stream = $disk->readStream($version->storage_path);

        if ($stream === null) {
            return false;
        }

        $signature = fread($stream, 2);
        fclose($stream);

        return $signature === 'PK';
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        if ($this->representations->find($version, RepresentationKind::StructuredXml) !== null) {
            return true;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localZip = tempnam(sys_get_temp_dir(), 'gsfx_');

        try {
            file_put_contents($localZip, $disk->get($version->storage_path));

            $zip = new ZipArchive;
            if ($zip->open($localZip) !== true) {
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, 'не удалось открыть контейнер как ZIP');

                return false;
            }

            $dataXml = $zip->getFromName('Data.xml');
            if ($dataXml === false) {
                $zip->close();
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, 'в контейнере отсутствует Data.xml');

                return false;
            }

            $propertiesTxt = $zip->getFromName('Properties.txt');

            try {
                $gsfxDocument = $this->reader->parse((string) ($propertiesTxt !== false ? $propertiesTxt : ''), $dataXml);
            } catch (GsfxUnreadableException $e) {
                $zip->close();
                $this->markUnprocessable($document, UnprocessableReason::UnreadableFormat, $e->getMessage());

                return false;
            }

            $attachmentEntries = $this->safeAttachmentEntries($zip);
            if ($attachmentEntries === null) {
                $zip->close();
                $this->markUnprocessable($document, UnprocessableReason::ZipBomb, 'вложения контейнера превышают лимиты ai.prepare.archive.*');

                return false;
            }

            $this->storeRepresentations($version, $gsfxDocument, $disk);
            $this->extractAttachments($document, $version, $zip, $attachmentEntries, $disk);

            $zip->close();
        } finally {
            @unlink($localZip);
        }

        return true;
    }

    private function storeRepresentations(DocumentVersion $version, GsfxDocumentDto $gsfxDocument, Filesystem $disk): void
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
            'disk' => null,
            'size_bytes' => strlen($structuredXml),
            'mime' => 'application/json',
            'meta' => $meta,
            'generated_at' => now(),
        ]);

        $textPath = $this->preparedPath($version, RepresentationKind::Text);
        $disk->put($textPath, $text);

        $this->representations->storeOrUpdate($version, RepresentationKind::Text, [
            'storage_path' => $textPath,
            'disk' => null,
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

    /**
     * @return list<string>|null Безопасные пути `Attachments/**`, либо `null` при превышении лимитов.
     */
    private function safeAttachmentEntries(ZipArchive $zip): ?array
    {
        $fileCount = 0;
        $totalBytes = 0;
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string) $stat['name'];

            if (! str_starts_with($name, 'Attachments/')) {
                continue;
            }

            if (str_ends_with($name, '/') || ! $this->isSafeEntryName($name)) {
                continue;
            }

            $fileCount++;
            $totalBytes += (int) $stat['size'];
            $entries[] = $name;
        }

        if ($fileCount > (int) config('ai.prepare.archive.max_files', 200)
            || $totalBytes > (int) config('ai.prepare.archive.max_total_mb', 500) * 1024 * 1024) {
            return null;
        }

        return $entries;
    }

    /**
     * @param  list<string>  $entries
     */
    private function extractAttachments(Document $document, DocumentVersion $version, ZipArchive $zip, array $entries, Filesystem $disk): void
    {
        if ($entries === []) {
            return;
        }

        $tempFolder = 'tmp_extract/'.Str::uuid();
        $extractPath = storage_path('app/'.$tempFolder);

        try {
            $zip->extractTo($extractPath, $entries);

            foreach ($entries as $relativePath) {
                $this->indexChild($document, $version, $relativePath, $extractPath.'/'.$relativePath, $disk);
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
    }

    private function isSafeEntryName(string $name): bool
    {
        if ($name === '' || str_starts_with($name, '/') || str_contains($name, "\0")) {
            return false;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function indexChild(Document $document, DocumentVersion $version, string $relativePath, string $localPath, Filesystem $disk): void
    {
        $externalId = $version->id.':'.$relativePath;

        $existingChild = $this->storeRepository->findByExternalId($document->procurement_id, $externalId);
        if ($existingChild !== null && $this->storeRepository->latestVersion($existingChild->id) !== null) {
            return;
        }

        $content = file_get_contents($localPath);
        $hash = hash('sha256', $content);
        $storagePath = $this->store($disk, $hash, $content);

        DB::transaction(function () use ($document, $externalId, $relativePath, $content, $hash, $storagePath, $version): void {
            $child = $this->storeRepository->findByExternalId($document->procurement_id, $externalId)
                ?? $this->storeRepository->create([
                    'procurement_id' => $document->procurement_id,
                    'platform_id' => $document->platform_id,
                    'external_id' => $externalId,
                    'title' => basename($relativePath),
                    'kind' => null,
                    'source_url' => null,
                    'status' => DocumentStatus::Downloaded,
                ]);

            $childVersion = $this->storeRepository->createVersion([
                'document_id' => $child->id,
                'content_hash' => $hash,
                'size_bytes' => strlen($content),
                'filename' => basename($relativePath),
                'extension' => pathinfo($relativePath, PATHINFO_EXTENSION),
                'mime' => $this->mimeOf($content),
                'storage_path' => $storagePath,
                'downloaded_at' => now(),
                'parent_version_id' => $version->id,
            ]);

            if ($document->procurement_id !== null) {
                $this->procurementStore->storeEvent(
                    procurementId: $document->procurement_id,
                    eventType: ProcurementEventType::DocumentAdded,
                    occurredAt: now(),
                    entityRef: ['document_id' => $child->id, 'document_version_id' => $childVersion->id],
                );
            }
        });
    }

    private function store(Filesystem $disk, string $hash, string $content): string
    {
        $path = 'documents/'.substr($hash, 0, 2)."/{$hash}";

        if (! $disk->exists($path)) {
            $disk->put($path, $content);
        }

        return $path;
    }

    private function mimeOf(string $content): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? finfo_buffer($finfo, $content) : false;

        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function preparedPath(DocumentVersion $version, RepresentationKind $kind): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.{$kind->value}.{$kind->extension()}";
    }

    private function markUnprocessable(Document $document, UnprocessableReason $reason, string $detail): void
    {
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.$reason->value.": {$detail}",
        ]);
    }
}
