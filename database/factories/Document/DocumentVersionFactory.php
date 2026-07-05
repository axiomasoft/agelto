<?php

declare(strict_types=1);

namespace Database\Factories\Document;

use App\Models\Document\Document;
use App\Models\Document\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentVersion>
 */
final class DocumentVersionFactory extends Factory
{
    protected $model = DocumentVersion::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'content_hash' => hash(algo: 'sha256', data: fake()->unique()->uuid()),
            'size_bytes' => fake()->numberBetween(int1: 1024, int2: 5_000_000),
            'filename' => fake()->word().'.pdf',
            'extension' => 'pdf',
            'mime' => 'application/pdf',
            'storage_path' => 'documents/'.fake()->uuid().'.pdf',
            'downloaded_at' => now(),
            'parent_version_id' => null,
            'prepared_storage_path' => null,
        ];
    }
}
