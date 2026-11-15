<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Données de départ : catégories et règles. Aucun utilisateur n'est
     * créé ici : php artisan finance:user s'en charge.
     */
    public function run(): void
    {
        $this->call(CategorySeeder::class);
    }
}
