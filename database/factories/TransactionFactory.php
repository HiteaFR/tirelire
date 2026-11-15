<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\MerchantNormalizer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = 'PAIEMENT CB '.fake()->randomElement(['LIDL', 'CARREFOUR MARKET', 'BOULANGERIE DU MARCHE', 'PHARMACIE DU CENTRE']);

        return [
            'account_id' => Account::factory(),
            'category_id' => null,
            'date' => fake()->dateTimeBetween('-3 months')->format('Y-m-d'),
            'amount_cents' => -fake()->numberBetween(150, 9000),
            'label' => $label,
            'merchant' => (new MerchantNormalizer)->normalize($label),
            'balance_cents' => null,
            'source' => 'csv',
        ];
    }

    /** Crédit (salaire, remboursement…). */
    public function income(int $cents = 250000): static
    {
        return $this->state(fn (array $attributes) => ['amount_cents' => $cents, 'label' => 'VIR SEPA ACME CORP SALAIRE', 'merchant' => 'ACME CORP SALAIRE']);
    }

    /** Opération saisie à la main dans l'app. */
    public function manual(): static
    {
        return $this->state(fn (array $attributes) => ['source' => 'manual']);
    }
}
