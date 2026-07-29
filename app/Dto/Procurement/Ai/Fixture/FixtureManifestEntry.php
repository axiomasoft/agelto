<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Ai\Fixture;

use App\Enums\Procurement\AiDocumentType;
use App\Enums\Procurement\FixtureClass;
use App\Enums\Procurement\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Единственный носитель контракта одной записи `estimates/manifest.json` (D1/D20). Обязательные
 * поля — `code`/`storage_path`/`sha256`/`size_bytes`/`class`/`source_format`/`form`/`gold_source`/
 * `document_type`/`owner_document_type`/`title`; остальные nullable/дефолтные.
 * - code: код эталона, единственный контракт — `Fixture::CODE_PATTERN` (D13).
 * - storage_path: путь на диске эталонов, обязан совпадать с `Fixture::storagePathFor($code,
 *   $source_format)` — иначе конвенция раскладки (D17) нарушена.
 * - source_path: относительный путь в корпусе-источнике — провенанс, НЕ путь на диске эталонов.
 * - gold_path: путь к `*.gold.json` в git; `null`, пока файл не построен — пишет `ai:gold:build
 *   --update-manifest` (P5.3) вместе с самим файлом (D91: указатель и файл появляются одним актом,
 *   владелец поля — производитель файла gold, не генератор манифеста).
 * - document_type: фактический тип файла = ожидаемый вердикт скрининга (§6.1 document-type-axis.md,
 *   D90) — `class`/`form`/`expected_*`/`gold_source` остаются ФЛОСКИМИ полями манифеста и уезжают в
 *   `type_profile` только на границе импорта (`FixturesImportCommand`), не в самом файле.
 * - owner_document_type: тип, чей анализатор владеет корпусом и валидирует `type_profile` (§6.1).
 * - title: человекочитаемое название эталона, чисто читательское (D90, §6.3).
 */
final class FixtureManifestEntry extends Data
{
    /**
     * @param  list<string>  $quirks
     */
    public function __construct(
        public string $code,
        public string $storage_path,
        public string $sha256,
        public int $size_bytes,
        public FixtureClass $class,
        public string $source_format,
        public string $form,
        public GoldSource $gold_source,
        public AiDocumentType $document_type,
        public AiDocumentType $owner_document_type,
        public string $title,
        public ?string $source_path = null,
        public ?string $original_filename = null,
        public ?string $mime = null,
        public ?string $generator = null,
        public ?string $cluster_id = null,
        public array $quirks = [],
        public ?int $expected_estimates = null,
        public ?int $expected_rows = null,
        public ?string $gold_path = null,
        public bool $is_verified = false,
        public ?string $notes = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'code' => ['required', 'string', 'regex:'.Fixture::CODE_PATTERN],
            'storage_path' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($context): void {
                    $expected = Fixture::storagePathFor(
                        (string) ($context->payload['code'] ?? ''),
                        (string) ($context->payload['source_format'] ?? ''),
                    );

                    if ($value !== $expected) {
                        $fail("storage_path обязан быть «{$expected}» (конвенция D17), получено «{$value}»");
                    }
                },
            ],
            'sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'size_bytes' => ['required', 'integer', 'min:0'],
            'quirks' => ['array'],
            'quirks.*' => ['string', 'regex:/^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$/'],
        ];
    }
}
