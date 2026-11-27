<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Document;

use App\Models\Procurement\Document\Document;
use App\Models\Procurement\Document\DocumentVersion;
use App\Services\Storage\FileLocationResolver;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

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
            'disk' => fn () => app(FileLocationResolver::class)->currentDocumentsDisk(),
            'downloaded_at' => now(),
            'parent_version_id' => null,
            'revision_no' => fn (array $a) => (int) DB::table('prc_document_versions')->where('document_id', $a['document_id'])->max('revision_no') + 1,
            'blob_id' => fn (array $a) => CanonicalArtifactFactory::blob($a),
            'first_seen_at' => now(),
            'normalized_entry_path' => fn (array $a) => $a['archive_entry_path'] ?? null,
            'child_ordinal' => fn (array $a) => ($a['parent_version_id'] ?? null) === null ? null : 0,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DocumentVersion $version): void {
            DB::table('prc_documents')->where('id', $version->document_id)->update(['current_version_id' => $version->id]);
        });
    }

    /**
     * Явный записанный диск canonical fixture. По умолчанию материализуется текущий диск.
     */
    public function onDisk(string $disk): self
    {
        return $this->state(fn (): array => ['disk' => $disk]);
    }
}
