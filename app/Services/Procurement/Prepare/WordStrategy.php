<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Enums\Procurement\Document\DocumentStatus;
use App\Enums\Procurement\Document\RepresentationKind;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Repositories\Procurement\Document\DocumentStoreRepository;
use App\Repositories\Procurement\Document\RepresentationStoreRepository;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `doc`/`docx`/`rtf`/`odt` → PDF через LibreOffice (P3.4, D7). До этого item'а такие версии
 * падали в `PassthroughStrategy` и уходили в LLM как оригинал (RAG:—,
 * `findings/corpus-recon-2026-07-26.md` §1–§2). Конверсия здесь — единственный путь: упавшая
 * конверсия терминальна САМА ПО СЕБЕ (`unprocessable(conversion_failed)`), цепочка на этом
 * заканчивается — оригинал в LLM больше не уходит.
 */
final readonly class WordStrategy implements PreparationStrategy
{
    public function __construct(
        private RepresentationStoreRepository $representations,
        private DocumentStoreRepository $storeRepository,
        private LibreOfficeConverter $converter,
    ) {}

    public function canProcess(DocumentVersion $version): bool
    {
        return in_array($version->extension, ['doc', 'docx', 'rtf', 'odt'], true);
    }

    public function process(Document $document, DocumentVersion $version): bool
    {
        if ($this->representations->find($version, RepresentationKind::Pdf) !== null) {
            return true;
        }

        $disk = Storage::disk((string) config('crawler.documents_disk'));
        $localInput = tempnam(sys_get_temp_dir(), 'word_').'.'.$version->extension;
        $outputDir = sys_get_temp_dir().'/word_pdf_'.Str::uuid();

        try {
            File::ensureDirectoryExists($outputDir);
            file_put_contents($localInput, $disk->get($version->storage_path));

            $localOutput = $this->converter->toPdf($localInput, $outputDir);

            $pdf = (string) file_get_contents($localOutput);
            $preparedPath = $this->preparedPath($version);
            $disk->put($preparedPath, $pdf);

            $this->representations->storeOrUpdate($version, RepresentationKind::Pdf, [
                'storage_path' => $preparedPath,
                'disk' => null,
                'size_bytes' => strlen($pdf),
                'mime' => 'application/pdf',
                'meta' => [
                    'tool' => 'libreoffice',
                    'tool_version' => $this->converter->version(),
                ],
                'generated_at' => now(),
            ]);

            return true;
        } catch (RuntimeException $e) {
            $this->markUnprocessable($document, $e->getMessage());

            return true;
        } finally {
            @unlink($localInput);
            File::deleteDirectory($outputDir);
        }
    }

    private function preparedPath(DocumentVersion $version): string
    {
        return 'documents/'.substr($version->content_hash, 0, 2)."/{$version->content_hash}.prepared.pdf";
    }

    private function markUnprocessable(Document $document, string $detail): void
    {
        $this->storeRepository->update($document, [
            'status' => DocumentStatus::Unprocessable,
            'last_error' => 'unprocessable:'.UnprocessableReason::ConversionFailed->value.": {$detail}",
        ]);
    }
}
