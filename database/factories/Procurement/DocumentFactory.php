<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\DocumentStatus;
use App\Models\Procurement\Document;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
final class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'external_id' => fake()->unique()->uuid(),
            'title' => fake()->sentence(),
            'kind' => 'извещение',
            'source_url' => fake()->url(),
            'status' => DocumentStatus::Discovered,
            'first_seen_revision_id' => null,
            'removed_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'last_error_at' => null,
            'next_retry_at' => null,
            'failed_permanently' => false,
        ];
    }
}
