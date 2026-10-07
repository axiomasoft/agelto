<?php

declare(strict_types=1);

namespace Database\Factories\Procurement\Crawler;

use App\Enums\Procurement\Crawler\SourceFailureKind;
use App\Enums\Procurement\Crawler\SourceIncidentType;
use App\Models\Procurement\Crawler\SourceIncident;
use App\Models\Procurement\Platform\Platform;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SourceIncident> */
final class SourceIncidentFactory extends Factory
{
    protected $model = SourceIncident::class;

    public function definition(): array
    {
        return [
            'platform_id' => Platform::factory(),
            'type' => SourceIncidentType::BlockSkipped,
            'severity' => SourceIncidentType::BlockSkipped->severity(),
            'failure_kind' => SourceFailureKind::SourceBlocked,
            'http_status' => 403,
            'message' => 'Блок пропущен: площадка ответила 403.',
            'created_at' => now(),
        ];
    }
}
