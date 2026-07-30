<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use Database\Factories\Procurement\Ai\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Реестр эталонного ФАЙЛА (D6) — справочник корпуса EST-TRAIN с метаданными и квирками, отдельный
 * от версий эталонных ОТВЕТОВ `DatasetExample`. Поля профиля анализатора типа (`class`/`form`/
 * `expected_estimates`/`expected_rows`/`gold_source`, §6.2 root/document-type-axis.md) живут в
 * `type_profile` — читаются типизированными `profile*()` (не top-level property), без индексов
 * (§6.4 контракта).
 *
 * @property string $code
 * @property string $source_format
 * @property string $storage_path
 * @property string $sha256
 * @property int $size_bytes
 * @property string $mime
 * @property string $original_filename
 * @property string $source_path
 * @property string|null $generator
 * @property string|null $cluster_id
 * @property AiDocumentType $document_type
 * @property AiDocumentType $owner_document_type
 * @property string $title
 * @property Collection<int, string> $quirks
 * @property Collection<string, mixed> $type_profile
 * @property bool $is_verified
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property int|null $document_id
 * @property string|null $notes
 * @property-read Document|null $document
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
        'source_format',
        'storage_path',
        'sha256',
        'size_bytes',
        'mime',
        'original_filename',
        'source_path',
        'generator',
        'cluster_id',
        'document_type',
        'owner_document_type',
        'title',
        'quirks',
        'type_profile',
        'is_verified',
        'verified_by',
        'verified_at',
        'notes',
        'document_id',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => AiDocumentType::class,
            'owner_document_type' => AiDocumentType::class,
            'quirks' => AsCollection::class,
            'type_profile' => AsCollection::class,
            'is_verified' => 'bool',
            'verified_at' => 'datetime',
            'size_bytes' => 'integer',
        ];
    }

    /**
     * Класс эталона (позитив/негатив) — `type_profile->>'class'` (§6.2 контракта).
     */
    public function profileClass(): FixtureClass
    {
        return FixtureClass::from((string) $this->type_profile->get('class'));
    }

    /**
     * Форма документа (KS2, …) — `type_profile->>'form'` (§6.2 контракта).
     */
    public function profileForm(): string
    {
        return (string) $this->type_profile->get('form');
    }

    /**
     * Источник gold-эталона — `type_profile->>'gold_source'` (§6.2 контракта).
     */
    public function profileGoldSource(): GoldSource
    {
        return GoldSource::from((string) $this->type_profile->get('gold_source'));
    }

    public function profileExpectedEstimates(): ?int
    {
        $value = $this->type_profile->get('expected_estimates');

        return $value === null ? null : (int) $value;
    }

    public function profileExpectedRows(): ?int
    {
        $value = $this->type_profile->get('expected_rows');

        return $value === null ? null : (int) $value;
    }

    /**
     * Единственный построитель пути исходного файла эталона на диске `config('ai.fixtures.disk')`.
     */
    public static function storagePathFor(string $code, string $extension): string
    {
        return "{$code}/source.{$extension}";
    }

    /**
     * Следующий свободный номер в тройке «класс/формат/форма» (§6.4 контракта P1.2, D18):
     * пишется на выражениях `type_profile->class`/`type_profile->form` без индексов — размер
     * отношения (десятки строк) делает seq scan дешевле индекса, а индекс P5.1 заводить не
     * вправе (схему закрыл P1). Возвращённый код обязан матчить `CODE_PATTERN`.
     */
    public static function nextCodeFor(FixtureClass $class, string $sourceFormat, string $form): string
    {
        $existingCodes = self::query()
            ->where('source_format', $sourceFormat)
            ->where('type_profile->class', $class->value)
            ->where('type_profile->form', $form)
            ->pluck('code');

        $max = 0;

        foreach ($existingCodes as $code) {
            if (preg_match('/-(\d{3})$/', (string) $code, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $code = sprintf('%s-%s-%s-%03d', strtoupper($class->value), strtoupper($sourceFormat), $form, $max + 1);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \RuntimeException("Сгенерированный код «{$code}» не матчит Fixture::CODE_PATTERN.");
        }

        return $code;
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

    /**
     * Прямая связь «эталон ↔ рабочая копия» (§2 root/document-contour.md) — единственный
     * писатель: `resolveWorkingCopy()` (`GoldImportCommand`).
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(related: Document::class);
    }

    /**
     * Прогоны всех версий рабочей копии (P5.3, `RunsRelationManager`) — read-путь для витрины:
     * `document_id` эталона сопоставляется с `document_id` версии напрямую, минуя промежуточный
     * `documents.id` (у `Document` нет relation-метода `versions()` — раскладка репозиторием).
     */
    public function runs(): HasManyThrough
    {
        return $this->hasManyThrough(
            related: AnalysisRun::class,
            through: DocumentVersion::class,
            firstKey: 'document_id',
            secondKey: 'document_version_id',
            localKey: 'document_id',
            secondLocalKey: 'id',
        );
    }
}
