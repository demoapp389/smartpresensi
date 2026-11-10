<?php

namespace Database\Factories;

use App\Models\SchedulePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulePeriod>
 */
class SchedulePeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now()->startOfMonth()->addMonths(2);

        return [
            'name' => 'Jadwal Khusus '.$this->faker->word(),
            'start_date' => $startDate->toDateString(),
            'end_date' => $startDate->copy()->addDays(29)->toDateString(),
            'notes' => null,
            'is_active' => true,
        ];
    }
}
