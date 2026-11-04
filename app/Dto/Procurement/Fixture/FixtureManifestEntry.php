<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Fixture;

use App\Enums\Procurement\Ai\AiDocumentType;
use App\Enums\Procurement\Ai\FixtureClass;
use App\Enums\Procurement\Ai\GoldSource;
use App\Models\Procurement\Ai\Fixture;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Запись `estimates/manifest.json`. Обязательные поля: `code`, `storage_path`, `sha256`,
 * `size_bytes`, `class`, `source_format`, `form`, `gold_source`, `document_type`,
 * `owner_document_type`, `title`; остальные nullable или имеют default.
 * `code` соответствует `Fixture::CODE_PATTERN`, а `storage_path` — `Fixture::storagePathFor()`.
 * `source_path` хранит относительный путь в исходном корпусе, а не адрес на диске эталонов.
 * `gold_path` остаётся `null` до сборки: `estimate:gold:build --update-manifest` пишет его вместе с gold.
 * `document_type` задаёт ожидаемый вердикт скрининга. `owner_document_type` выбирает анализатор
 * корпуса и валидатор `type_profile`. `class`, `form`, `expected_*`, `gold_source` остаются
 * плоскими полями манифеста и переходят в `type_profile` на границе импорта.
 * `title` — человекочитаемое название эталона.
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
                    $payload = $context->payload;
                    $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';
                    $sourceFormat = is_array($payload) && is_string($payload['source_format'] ?? null) ? $payload['source_format'] : '';

                    $expected = Fixture::storagePathFor(
                        $code,
                        $sourceFormat,
                    );

                    if (is_string($value) && $value !== $expected) {
                        $fail("storage_path обязан быть «{$expected}», получено «{$value}»");
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
