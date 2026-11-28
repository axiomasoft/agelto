<?php

declare(strict_types=1);

namespace Database\Factories\Procurement;

use App\Enums\Procurement\ProtocolType;
use App\Models\Procurement\Procurement;
use App\Models\Procurement\Protocol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Protocol>
 */
final class ProtocolFactory extends Factory
{
    protected $model = Protocol::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'type' => ProtocolType::Summary->value,
            'stage' => null,
            'source_number' => fake()->numerify('###'),
            'signed_at' => now(),
            'published_at' => now(),
            'document_version_id' => null,
            'payload' => null,
        ];
    }
}
