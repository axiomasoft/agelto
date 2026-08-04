<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentRepresentation>
 */
final class DocumentRepresentationFactory extends Factory
{
    protected $model = DocumentRepresentation::class;

    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'kind' => RepresentationKind::Pdf,
            'storage_path' => 'documents/'.fake()->uuid().'.prepared.pdf',
            'disk' => null,
            'size_bytes' => fake()->numberBetween(int1: 1024, int2: 5_000_000),
            'page_count' => fake()->numberBetween(int1: 1, int2: 50),
            'token_estimate' => null,
            'mime' => 'application/pdf',
            'meta' => [],
            'generated_at' => now(),
        ];
    }
}
