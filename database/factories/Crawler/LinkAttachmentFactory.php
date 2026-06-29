<?php

declare(strict_types=1);

namespace Database\Factories\Crawler;

use App\Models\Crawler\Link;
use App\Models\Crawler\LinkAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LinkAttachment>
 */
final class LinkAttachmentFactory extends Factory
{
    protected $model = LinkAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'link_id' => Link::factory(),
            'external_id' => 'att-'.fake()->unique()->numerify('#####'),
            'name' => 'smeta.xlsx',
            'url' => 'https://example.com/smeta-'.fake()->unique()->numerify('#####').'.xlsx',
        ];
    }
}
