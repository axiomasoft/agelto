<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Database\Factories\Procurement\Ai\DatasetExampleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Эталонный пример (gold) для датасета оценки качества AI-анализа.
 *
 * @property int $document_version_id
 * @property string $document_type
 * @property string $source
 * @property int $version
 * @property int|null $analysis_id
 * @property-read DocumentVersion|null $documentVersion
 * @property-read Fixture|null $fixture
 * @property-read Analysis|null $analysis
 */
#[UseFactory(DatasetExampleFactory::class)]
final class DatasetExample extends Model
{
    use HasFactory;

    protected $table = 'prc_ai_dataset_examples';

    protected $fillable = [
        'document_version_id',
        'fixture_id',
        'analysis_id',
        'document_type',
        'task',
        'gold',
        'gold_ref',
        'source',
        'verified_by',
        'verified_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'gold' => AsCollection::class,
            'verified_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(related: DocumentVersion::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'verified_by');
    }

    public function fixture(): BelongsTo
    {
        return $this->belongsTo(related: Fixture::class);
    }

    public function analysis(): BelongsTo
    {
        return $this->belongsTo(related: Analysis::class);
    }
}
