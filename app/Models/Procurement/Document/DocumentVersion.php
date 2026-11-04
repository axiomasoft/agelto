<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use App\Models\Procurement\Ai\Analysis;
use App\Models\Procurement\Ai\AnalysisRun;
use App\Models\Procurement\Ai\DatasetExample;
use App\Support\Files\Sha256Cast;
use Database\Factories\Procurement\Document\DocumentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Контент-адресованная версия файла документа (дедуп по sha256 в рамках документа).
 *
 * Подготовленные представления версии живут в `prc_document_representations`
 * ({@see DocumentRepresentation}) — отдельной колонки под подготовленный путь у версии нет.
 *
 * @property int $document_id
 * @property string $content_hash
 * @property int $size_bytes
 * @property string $filename
 * @property string $extension
 * @property string $mime
 * @property string $storage_path
 * @property string $disk
 * @property Carbon $downloaded_at
 * @property int|null $parent_version_id
 * @property string|null $archive_entry_path
 * @property-read Document|null $document
 */
#[UseFactory(DocumentVersionFactory::class)]
#[Fillable([
    'document_id', 'revision_no', 'blob_id', 'first_seen_at', 'normalized_entry_path', 'child_ordinal',
    'content_hash',
    'size_bytes',
    'filename',
    'extension',
    'mime',
    'storage_path',
    'disk',
    'downloaded_at',
    'parent_version_id',
    'archive_entry_path',
])]
#[Table(name: 'prc_document_versions')]
final class DocumentVersion extends Model
{
    /** @use HasFactory<DocumentVersionFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'content_hash' => Sha256Cast::class,
            'size_bytes' => 'integer',
            'downloaded_at' => 'datetime',
        ];
    }

    /**
     * Родительский документ (`AnalysesTable` — колонка «Документ»); read-путь, репозитории
     * этой связью не пользуются (прямые запросы по `document_id`).
     */
    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(related: Document::class);
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(related: self::class, foreignKey: 'parent_version_id');
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function archiveChildren(): HasMany
    {
        return $this->hasMany(related: self::class, foreignKey: 'parent_version_id');
    }

    /** @return HasMany<AnalysisRun, $this> */
    public function analysisRuns(): HasMany
    {
        return $this->hasMany(related: AnalysisRun::class);
    }

    /** @return HasMany<Analysis, $this> */
    public function analyses(): HasMany
    {
        return $this->hasMany(related: Analysis::class);
    }

    /** @return HasMany<DatasetExample, $this> */
    public function datasetExamples(): HasMany
    {
        return $this->hasMany(related: DatasetExample::class);
    }
}
