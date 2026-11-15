<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'icon' => fake()->randomElement(['📁', '🛒', '🍔', '🚗', '🎬']),
            'color' => fake()->hexColor(),
            'kind' => 'expense',
            'fixed' => false,
            'position' => 0,
        ];
    }

    /** Revenu (salaire, remboursement…). */
    public function income(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'income']);
    }

    /** Mouvement interne (épargne, virement entre comptes), exclu des statistiques. */
    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => ['kind' => 'transfer']);
    }

    /** Charge fixe pour une dépense, revenu régulier pour un revenu. */
    public function fixed(): static
    {
        return $this->state(fn (array $attributes) => ['fixed' => true]);
    }
}
