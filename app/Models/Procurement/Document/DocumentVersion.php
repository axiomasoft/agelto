<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use Database\Factories\Procurement\Document\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Контент-адресованная версия файла документа (дедуп по sha256 в рамках документа).
 *
 * Подготовленные представления версии живут в `prc_document_representations`
 * ({@see DocumentRepresentation}, D25) — отдельной колонки под подготовленный путь у версии нет.
 *
 * @property int $document_id
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
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * Родительский документ (P9.2, `AnalysesTable` — колонка «Документ»); read-путь, репозитории
     * этой связью не пользуются (прямые запросы по `document_id`).
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(related: Document::class);
    }
}
