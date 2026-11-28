<?php

declare(strict_types=1);

namespace Database\Factories\Platforms\Eis;

use App\Models\Platforms\Eis\EisDetails;
use App\Models\Procurement\Procurement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EisDetails>
 */
final class EisDetailsFactory extends Factory
{
    protected $model = EisDetails::class;

    public function definition(): array
    {
        return [
            'procurement_id' => Procurement::factory(),
            'ikz_year' => (int) date('y'),
            'ikz_customer_code' => fake()->numerify('##########'),
            'ikz_plan_number' => fake()->numerify('##############'),
            'ikz_plangraph_number' => fake()->numerify('###'),
            'ikz_okpd_class' => fake()->numerify('##'),
            'ikz_okpd_subclass' => fake()->numerify('#'),
            'ikz_okpd_group' => fake()->numerify('#'),
            'ikz_kvr' => fake()->numerify('###'),
            'plan_graph_position' => fake()->numerify('###'),
            'plan_graph_url' => fake()->url(),
            'bid_security_required' => false,
            'bid_security_percent' => null,
            'bid_security_amount' => null,
            'performance_security_required' => false,
            'performance_security_percent' => null,
            'performance_security_amount' => null,
            'is_smp_only' => false,
            'anti_dumping_applied' => false,
            'treasury_support' => false,
            'advantages' => null,
            'national_regime' => null,
            'participant_requirements' => null,
            'etp_name' => fake()->company(),
            'etp_url' => fake()->url(),
            'execution_starts_at' => null,
            'execution_ends_at' => null,
            'contact_person' => null,
            'print_form_urls' => null,
        ];
    }
}
