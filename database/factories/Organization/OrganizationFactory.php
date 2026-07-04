<?php

declare(strict_types=1);

namespace Database\Factories\Organization;

use App\Models\Organization\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
final class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'inn' => fake()->unique()->numerify(string: '##########'),
            'kpp' => fake()->numerify(string: '#########'),
            'ogrn' => fake()->numerify(string: '#############'),
            'eruz_number' => fake()->unique()->numerify(string: '#############'),
            'full_name' => fake()->company(),
            'short_name' => fake()->companySuffix(),
            'address_raw' => fake()->address(),
            'region_code' => fake()->numerify(string: '##'),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->companyEmail(),
            'attributes' => null,
        ];
    }
}
