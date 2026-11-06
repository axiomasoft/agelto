<?php

declare(strict_types=1);

namespace App\Models\Procurement\Ai;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Models\User;
use App\Services\Storage\FileLocationResolver;
use Database\Factories\Procurement\Ai\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Реестр эталонного ФАЙЛА — справочник корпуса EST-TRAIN с метаданными и квирками, отдельный
 * от версий эталонных ОТВЕТОВ `DatasetExample`. Поля профиля анализатора типа (`class`/`form`/
 * `expected_estimates`/`expected_rows`/`gold_source`) живут в
 * `type_profile` — читаются типизированными `profile*` (не top-level property), без индексов.
 *
 * @property string $code
 * @property string $source_format
 * @property string $storage_path
 * @property string|null $disk
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
#[Fillable([
    'code',
    'source_format',
    'storage_path',
    'disk',
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
])]
#[Table(name: 'prc_ai_fixtures')]
final class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    /**
     * Единственный носитель контракта кода эталона: `<CLASS>-<FORMAT>-<FORM>-<NNN>`.
     */
    public const string CODE_PATTERN = '/^(EST|NEG)-(XLSX|XLS|PDF|GSFX|DOCX|JPG)-[A-Z0-9]{2,10}-[0-9]{3}$/';

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
     * Класс эталона (позитив/негатив) — `type_profile->>'class'`.
     */
    public function profileClass(): FixtureClass
    {
        return FixtureClass::from($this->profileRequiredString('class'));
    }

    /**
     * Форма документа (KS2, …) — `type_profile->>'form'`.
     */
    public function profileForm(): string
    {
        return $this->profileRequiredString('form');
    }

    /**
     * Источник gold-эталона — `type_profile->>'gold_source'`.
     */
    public function profileGoldSource(): GoldSource
    {
        return GoldSource::from($this->profileRequiredString('gold_source'));
    }

    public function profileExpectedEstimates(): ?int
    {
        return $this->profileNullableInt('expected_estimates');
    }

    public function profileExpectedRows(): ?int
    {
        return $this->profileNullableInt('expected_rows');
    }

    /**
     * Единственный построитель пути исходного файла эталона на текущем диске эталонов
     * ({@see FileLocationResolver::currentFixturesDisk}).
     */
    public static function storagePathFor(string $code, string $extension): string
    {
        return "{$code}/source.{$extension}";
    }

    /**
     * Следующий свободный номер в тройке «класс/формат/форма».
     * Запрос использует `type_profile->class`/`type_profile->form`; для небольшого справочника
     * последовательное сканирование дешевле дополнительного индекса.
     * Возвращённый код обязан соответствовать `CODE_PATTERN`.
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
            if (! is_string($code)) {
                throw new RuntimeException('Код fixture должен быть строкой.');
            }

            if (preg_match('/-(\d{3})$/', $code, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $code = sprintf('%s-%s-%s-%03d', strtoupper($class->value), strtoupper($sourceFormat), $form, $max + 1);

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new RuntimeException("Сгенерированный код «{$code}» не матчит Fixture::CODE_PATTERN.");
        }

        return $code;
    }

    /**
     * Единственный построитель пути производного представления эталона.
     */
    public static function derivedPathFor(string $code, string $name): string
    {
        return "{$code}/derived/{$name}";
    }

    /** @return HasMany<DatasetExample, $this> */
    public function datasetExamples(): HasMany
    {
        return $this->hasMany(related: DatasetExample::class);
    }

    /** @return BelongsTo<User, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(related: User::class, foreignKey: 'verified_by');
    }

    /**
     * Прямая связь «эталон ↔ рабочая копия» — единственный
     * писатель: `resolveWorkingCopy` (`GoldImportCommand`).
     */
    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(related: Document::class);
    }

    /**
     * Прогоны всех версий рабочей копии (`RunsRelationManager`) — read-путь для витрины:
     * `document_id` эталона сопоставляется с `document_id` версии напрямую, минуя промежуточный
     * `documents.id` (у `Document` нет relation-метода `versions` — раскладка репозиторием).
     */
    /** @return HasManyThrough<AnalysisRun, DocumentVersion, $this> */
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

    private function profileRequiredString(string $key): string
    {
        $value = $this->type_profile->get($key);

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Fixture type_profile.{$key} должен быть непустой строкой.");
        }

        return $value;
    }

    private function profileNullableInt(string $key): ?int
    {
        $value = $this->type_profile->get($key);

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException("Fixture type_profile.{$key} должен быть целым числом или null.");
    }
}
