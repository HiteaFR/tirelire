<?php

namespace Tests\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Catégories et règles des tests : mêmes clés, types et charges fixes que
 * CategorySeeder, noms en français comme une base installée en fr, mais
 * seulement les motifs fictifs dont les tests ont besoin, pour que les tests
 * ne dépendent pas des règles livrées.
 */
class TestCategorySeeder extends Seeder
{
    public function run(): void
    {
        /** @var array<int, array{0: string, 1: string, 2: string, 3: bool, 4: array<int, array{0: string, 1: int}>}> $definitions */
        $definitions = [
            ['salary', 'Salaire', 'income', true, [['EMPLOYEUR EXEMPLE', 10]]],
            ['freelance_income', 'Revenus freelance', 'income', true, [['CLIENT EXEMPLE', 10]]],
            ['other_income', 'Autres revenus', 'income', false, []],
            ['housing', 'Logement', 'expense', true, [['LOYER', 10]]],
            ['subscriptions', 'Abonnements & télécom', 'expense', true, [['BOUYGUES TELECOM', 10]]],
            ['groceries', 'Courses', 'expense', false, [['LIDL', 20]]],
            ['restaurants', 'Restos & livraison', 'expense', false, []],
            ['bars', 'Bars & tabac', 'expense', false, []],
            ['shopping', 'Shopping', 'expense', false, []],
            ['bank_fees', 'Frais bancaires', 'expense', false, []],
            ['personal_transfers', 'Virements perso', 'expense', false, []],
            // Un virement depuis l'épargne porte aussi le nom du titulaire :
            // la règle d'épargne doit passer avant (priorité plus petite).
            ['savings', 'Épargne & crypto', 'transfer', false, [['LIVRET EXEMPLE', 10]]],
            ['internal_transfers', 'Virements internes', 'transfer', false, [['JEAN DUPONT', 15], ['DUPONT JEAN', 15]]],
        ];

        foreach ($definitions as $position => [$key, $name, $kind, $fixed, $rules]) {
            $category = Category::create(['key' => $key, 'name' => $name, 'kind' => $kind, 'fixed' => $fixed, 'position' => $position]);

            foreach ($rules as [$pattern, $priority]) {
                $category->rules()->create(['pattern' => $pattern, 'priority' => $priority]);
            }
        }
    }
}
