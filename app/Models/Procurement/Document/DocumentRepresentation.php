<?php

declare(strict_types=1);

namespace App\Models\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use Database\Factories\Procurement\Document\DocumentRepresentationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Представление версии документа (D25) — результат работы стратегии подготовки: чем сделано,
 * какой версией инструмента и с какими измеримыми признаками. Пара «версия + kind» уникальна:
 * повторная генерация обновляет запись, а не плодит вторую.
 *
 * @property int $document_version_id
 * @property RepresentationKind $kind
 * @property string $storage_path
 * @property string|null $disk
 * @property int|null $size_bytes
 * @property int|null $page_count
 * @property int|null $token_estimate
 * @property string $mime
 * @property Collection<string, mixed> $meta
 * @property Carbon $generated_at
 */
#[UseFactory(DocumentRepresentationFactory::class)]
#[Fillable([
    'document_version_id',
    'kind',
    'storage_path',
    'disk',
    'size_bytes',
    'page_count',
    'token_estimate',
    'mime',
    'meta',
    'generated_at',
])]
#[Table(name: 'prc_document_representations')]
final class DocumentRepresentation extends Model
{
    /** @use HasFactory<DocumentRepresentationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => RepresentationKind::class,
            'meta' => AsCollection::class,
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'token_estimate' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class, foreignKey: 'document_version_id');
    }
}
