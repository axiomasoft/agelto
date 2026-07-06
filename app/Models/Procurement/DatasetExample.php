<?php

declare(strict_types=1);

namespace App\Models\Procurement;

use App\Models\User;
use Database\Factories\Procurement\DatasetExampleFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Эталонный пример (gold) для датасета оценки качества AI-анализа.
 *
 * @use HasFactory<DatasetExampleFactory>
 */
final class DatasetExample extends Model
{
    /** @use HasFactory<DatasetExampleFactory> */
    use HasFactory;

    protected $table = 'prc_ai_dataset_examples';

    protected $fillable = [
        'document_version_id',
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
}
