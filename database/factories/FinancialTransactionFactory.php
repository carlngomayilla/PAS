<?php

namespace Database\Factories;

use App\Models\Action;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'action_id' => Action::query()->value('id') ?? 1,
            'operation_type' => fake()->randomElement([FinancialTransaction::TYPE_COMMITMENT, FinancialTransaction::TYPE_DISBURSEMENT]),
            'amount' => fake()->randomFloat(2, 1000, 500000),
            'operated_on' => fake()->date(),
            'payment_method' => fake()->randomElement(['virement', 'cheque', 'ordre_paiement']),
            'reference' => fake()->bothify('FIN-####'),
            'beneficiary' => fake()->company(),
            'comment' => fake()->sentence(),
            'recorded_by' => User::factory(),
        ];
    }
}
