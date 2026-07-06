<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use Database\Factories\Procurement\Document\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Контент-адресованная версия файла документа (дедуп по sha256 в рамках документа).
 */
#[UseFactory(DocumentVersionFactory::class)]
final class DocumentVersion extends Model
{
    use HasFactory;

    protected $table = 'prc_document_versions';

    protected $fillable = [
        'document_id',
        'content_hash',
        'size_bytes',
        'filename',
        'extension',
        'mime',
        'storage_path',
        'downloaded_at',
        'parent_version_id',
        'prepared_storage_path',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }
}
