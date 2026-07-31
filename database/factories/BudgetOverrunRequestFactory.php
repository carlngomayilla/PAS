<?php

namespace Database\Factories;

use App\Models\BudgetOverrunRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetOverrunRequest>
 */
class BudgetOverrunRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_type' => BudgetOverrunRequest::SCOPE_ACTION,
            'scope_id' => 1,
            'base_budget' => fake()->randomFloat(2, 100000, 500000),
            'requested_extra' => fake()->randomFloat(2, 10000, 100000),
            'status' => BudgetOverrunRequest::STATUS_PENDING_DIRECTOR,
            'reason' => fake()->sentence(12),
            'requested_by' => User::factory(),
        ];
    }
}
