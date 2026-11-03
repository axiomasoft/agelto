<?php

declare(strict_types=1);

namespace App\Services\Procurement\Prepare;

use App\Dto\Procurement\Prepare\DocumentFormatEvidence;
use App\Enums\Procurement\Document\UnprocessableReason;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Storage\FileLocationResolver;
use App\Support\Files\MimeDetector;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final readonly class DocumentFormatInspector
{
    public function __construct(
        private DocumentFormatRegistry $registry,
        private FileLocationResolver $locations,
    ) {}

    public function inspect(DocumentVersion $version): DocumentFormatEvidence
    {
        $extension = strtolower(pathinfo(basename($version->filename), PATHINFO_EXTENSION));
        $entry = $this->registry->forExtension($extension);

        if ($entry === null) {
            return new DocumentFormatEvidence(null, $extension, $version->mime, null, UnprocessableReason::UnsupportedFormat, 'расширение отсутствует в закрытом registry');
        }

        $maxBytes = $this->positiveIntegerConfig(key: 'documents.inspection_max_bytes', default: 104_857_600);

        if ($version->size_bytes > $maxBytes) {
            return new DocumentFormatEvidence($entry['format'], $extension, $version->mime, null, UnprocessableReason::TooLarge, "размер версии превышает {$maxBytes} байт");
        }

        $location = $this->locations->forDocumentVersion($version);
        $stream = Storage::disk($location->disk)->readStream($location->path);

        if (! is_resource($stream)) {
            return new DocumentFormatEvidence($entry['format'], $extension, $version->mime, null, UnprocessableReason::FormatMismatch, 'объект версии не читается через FileLocationResolver');
        }

        try {
            $content = fread($stream, max(1, $this->positiveIntegerConfig(key: 'documents.signature_bytes', default: 8192)));

            if (! is_string($content)) {
                throw new RuntimeException('Не удалось прочитать сигнатуру документа.');
            }
        } finally {
            fclose($stream);
        }

        $mime = MimeDetector::of($content);

        if (! in_array($mime, $entry['mimes'], true) || ! $this->probe(extension: $extension, content: $content)) {
            return new DocumentFormatEvidence($entry['format'], $extension, $mime, null, UnprocessableReason::FormatMismatch, 'extension, detected MIME или signature не совпали');
        }

        return new DocumentFormatEvidence($entry['format'], $extension, $mime, $entry['strategy'], null, null);
    }

    private function probe(string $extension, string $content): bool
    {
        return match ($extension) {
            'pdf' => str_starts_with($content, '%PDF-'),
            'png' => str_starts_with($content, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($content, "\xff\xd8\xff"),
            'bmp' => str_starts_with($content, 'BM'),
            'tif', 'tiff' => str_starts_with($content, "II*\0") || str_starts_with($content, "MM\0*"),
            'webp' => substr($content, 0, 4) === 'RIFF' && substr($content, 8, 4) === 'WEBP',
            'rtf' => str_starts_with(ltrim($content), '{\\rtf'),
            '7z' => str_starts_with($content, "7z\xbc\xaf'\x1c"),
            'rar' => str_starts_with($content, "Rar!\x1a\x07\0") || str_starts_with($content, "Rar!\x1a\x07\x01\0"),
            'xls', 'doc' => str_starts_with($content, "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"),
            'xlsx', 'docx', 'odt', 'gsfx', 'zip' => str_starts_with($content, 'PK'),
            default => true,
        };
    }

    private function positiveIntegerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) || $value <= 0) {
            throw new \UnexpectedValueException("{$key} must be a positive integer.");
        }

        return $value;
    }
}
