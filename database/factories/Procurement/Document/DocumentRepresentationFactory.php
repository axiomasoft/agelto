<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Document;

use App\Enums\Procurement\Document\RepresentationKind;
use App\Models\Procurement\Document\DocumentRepresentation;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Storage\FileLocationResolver;
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
            'preparation_recipe_hash' => hash('sha256', 'fixture-recipe/1'),
            'range_key' => 'complete',
            'tool_version' => 'fixture/1',
            'coverage_manifest' => '{"unit":"page","source_units":1,"covered":[],"missing":[[0,1]],"complete":false}',
            'coverage_manifest_schema_version' => 1,
            'storage_path' => 'documents/'.fake()->uuid().'.prepared.pdf',
            'disk' => fn () => app(FileLocationResolver::class)->currentDocumentsDisk(),
            'size_bytes' => fake()->numberBetween(int1: 1024, int2: 5_000_000),
            'page_count' => fake()->numberBetween(int1: 1, int2: 50),
            'token_estimate' => null,
            'mime' => 'application/pdf',
            'meta' => [],
            'generated_at' => now(),
            'content_hash' => fn (array $a) => hash('sha256', $a['storage_path']),
            'blob_id' => fn (array $a) => CanonicalArtifactFactory::blob($a),
        ];
    }
}
