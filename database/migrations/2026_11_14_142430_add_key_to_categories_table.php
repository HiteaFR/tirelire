<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Clé stable des catégories livrées par CategorySeeder : le nom affiché
     * dépend de la langue d'installation et peut être modifié dans l'app, la
     * clé non. Les indices de catégorie des parseurs et le seeder s'y fient.
     */
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->string('key', 50)->nullable()->unique()->after('id');
        });

        // Bases existantes, seedées en français avant l'ajout de la clé.
        $keysByFrenchName = [
            'Salaire' => 'salary',
            'Revenus freelance' => 'freelance_income',
            'Remboursements santé' => 'health_refunds',
            'Autres revenus' => 'other_income',
            'Logement' => 'housing',
            'Crédits & échéances' => 'loans',
            'Pension & CAF' => 'child_support',
            'Impôts' => 'taxes',
            'École & enfants' => 'school_children',
            'Abonnements & télécom' => 'subscriptions',
            'Courses' => 'groceries',
            'Restos & livraison' => 'restaurants',
            'Bars & tabac' => 'bars',
            'Transport & auto' => 'transport',
            'Santé' => 'health',
            'Shopping' => 'shopping',
            'Loisirs & culture' => 'leisure',
            'Frais bancaires' => 'bank_fees',
            'Retraits espèces' => 'cash_withdrawals',
            'Virements perso' => 'personal_transfers',
            'Épargne & crypto' => 'savings',
            'Virements internes' => 'internal_transfers',
        ];

        foreach ($keysByFrenchName as $name => $key) {
            $id = DB::table('categories')->where('name', $name)->orderBy('id')->value('id');

            if ($id !== null && ! DB::table('categories')->where('key', $key)->exists()) {
                DB::table('categories')->where('id', $id)->update(['key' => $key]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn('key');
        });
    }
};
