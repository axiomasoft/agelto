<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Models\Procurement\DatasetExample;
use App\Models\Procurement\DocumentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatasetExample>
 */
final class DatasetExampleFactory extends Factory
{
    protected $model = DatasetExample::class;

    public function definition(): array
    {
        return [
            'document_version_id' => DocumentVersion::factory(),
            'task' => 'screening',
            'gold' => ['is_estimate' => true],
            'gold_ref' => null,
            'source' => 'manual',
            'verified_by' => null,
            'verified_at' => null,
            'version' => 1,
        ];
    }
}
