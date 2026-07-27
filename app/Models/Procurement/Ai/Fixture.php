<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\User;
use Database\Factories\Procurement\Ai\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Реестр эталонного ФАЙЛА (D6) — справочник корпуса EST-TRAIN с метаданными и квирками, отдельный
 * от версий эталонных ОТВЕТОВ `DatasetExample`.
 */
#[UseFactory(FixtureFactory::class)]
final class Fixture extends Model
{
    use HasFactory;

    /**
     * Единственный носитель контракта кода эталона (D13): `<CLASS>-<FORMAT>-<FORM>-<NNN>`.
     */
    public const CODE_PATTERN = '/^(EST|NEG)-(XLSX|XLS|PDF|GSFX|DOCX|JPG)-[A-Z0-9]{2,10}-[0-9]{3}$/';

    protected $table = 'prc_ai_fixtures';

    protected $fillable = [
        'code',
        'class',
        'source_format',
        'form',
        'storage_path',
        'sha256',
        'size_bytes',
        'mime',
        'original_filename',
        'source_path',
        'generator',
        'cluster_id',
        'doc_type',
        'quirks',
        'expected_estimates',
        'expected_rows',
        'gold_source',
        'is_verified',
        'verified_by',
        'verified_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'class' => FixtureClass::class,
            'gold_source' => GoldSource::class,
            'doc_type' => AiDocumentType::class,
            'quirks' => AsCollection::class,
            'is_verified' => 'bool',
            'verified_at' => 'datetime',
            'size_bytes' => 'integer',
            'expected_estimates' => 'integer',
            'expected_rows' => 'integer',
        ];
    }

    /**
     * Единственный построитель пути исходного файла эталона на диске `config('ai.fixtures.disk')`.
     */
    public static function storagePathFor(string $code, string $extension): string
    {
        return "{$code}/source.{$extension}";
    }

    /**
     * Единственный построитель пути производного представления (P3/P6) эталона.
     */
    public static function derivedPathFor(string $code, string $name): string
    {
        return "{$code}/derived/{$name}";
    }

    public function datasetExamples(): HasMany
    {
        return $this->hasMany(related: DatasetExample::class);
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'verified_by');
    }
}
