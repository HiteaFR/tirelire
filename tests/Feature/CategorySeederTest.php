<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\User;
use App\Services\Categorizer;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CategorySeederTest extends TestCase
{
    use RefreshDatabase;

    private function categoryOf(string $label): ?string
    {
        return Category::find(app(Categorizer::class)->categoryIdFor($label))?->key;
    }

    public function test_database_seeder_creates_the_categories_and_no_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(22, Category::count());
        $this->assertEquals(['income' => 4, 'expense' => 16, 'transfer' => 2], Category::pluck('kind')->countBy()->all());
        $this->assertTrue(Category::where('key', 'housing')->value('fixed'));
        $this->assertSame('Housing', Category::where('key', 'housing')->value('name')); // langue par défaut : anglais
        $this->assertSame('groceries', $this->categoryOf('PAIEMENT CB CARREFOUR MARKET'));
    }

    public function test_a_pattern_ending_with_a_space_matches_a_label_ending_with_the_word_but_not_a_longer_word(): void
    {
        $this->seed(CategorySeeder::class);

        $this->assertSame('housing', $this->categoryOf('Prélèvement EDF'));
        $this->assertSame('transport', $this->categoryOf('ESSO'));
        $this->assertNull($this->categoryOf('PAIEMENT CB EVRY ESSONNE'));
    }

    public function test_running_it_twice_duplicates_nothing_and_keeps_changes_made_in_the_app(): void
    {
        $this->seed(CategorySeeder::class);
        Category::where('key', 'groceries')->update(['color' => '#000000', 'name' => 'Food']);
        $counts = [Category::count(), CategoryRule::count()];

        $this->seed(CategorySeeder::class);

        $this->assertSame($counts, [Category::count(), CategoryRule::count()]);
        $this->assertSame(['#000000', 'Food'], [Category::where('key', 'groceries')->value('color'), Category::where('key', 'groceries')->value('name')]);
    }

    public function test_private_rules_are_loaded_from_the_configured_json_file(): void
    {
        config(['finance.category_rules' => 'tests/Fixtures/category-rules.json']);

        $this->seed(CategorySeeder::class);
        $this->seed(CategorySeeder::class);

        $this->assertSame('salary', $this->categoryOf('VIR EMPLOYEUR EXEMPLE'));                 // par clé
        $this->assertSame('internal_transfers', $this->categoryOf('VIR INST JEAN DUPONT'));    // par nom
        $this->assertSame('groceries', $this->categoryOf('PAIEMENT CB MARCHE DU VILLAGE'));
        $this->assertSame(100, CategoryRule::where('pattern', 'MARCHE DU VILLAGE')->sole()->priority); // motif du fichier mis en majuscules
        $this->assertTrue(CategoryRule::where('pattern', 'BUS ')->exists()); // espace finale conservée
    }

    public function test_categories_are_named_in_the_installation_language(): void
    {
        app()->setLocale('fr');

        $this->seed(CategorySeeder::class);

        $this->assertSame([
            'salary' => 'Salaire', 'freelance_income' => 'Revenus freelance', 'health_refunds' => 'Remboursements santé',
            'other_income' => 'Autres revenus', 'housing' => 'Logement', 'loans' => 'Crédits & échéances',
            'child_support' => 'Pension & CAF', 'taxes' => 'Impôts', 'school_children' => 'École & enfants',
            'subscriptions' => 'Abonnements & télécom', 'groceries' => 'Courses', 'restaurants' => 'Restos & livraison',
            'bars' => 'Bars & tabac', 'transport' => 'Transport & auto', 'health' => 'Santé', 'shopping' => 'Shopping',
            'leisure' => 'Loisirs & culture', 'bank_fees' => 'Frais bancaires', 'cash_withdrawals' => 'Retraits espèces',
            'personal_transfers' => 'Virements perso', 'savings' => 'Épargne & crypto', 'internal_transfers' => 'Virements internes',
        ], Category::orderBy('position')->pluck('name', 'key')->all());
    }

    public function test_a_french_category_without_key_is_adopted_instead_of_duplicated(): void
    {
        app()->setLocale('fr');
        $existing = Category::create(['name' => 'Courses', 'kind' => 'expense', 'color' => '#000000']);

        $this->seed(CategorySeeder::class);

        $this->assertSame(22, Category::count());
        $this->assertSame(['groceries', '#000000'], [$existing->fresh()->key, $existing->fresh()->color]);
    }

    public function test_the_key_migration_backfills_categories_seeded_in_french(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        DB::table('categories')->insert([
            ['name' => 'Courses', 'kind' => 'expense'],
            ['name' => 'Épargne & crypto', 'kind' => 'transfer'],
            ['name' => 'Animaux', 'kind' => 'expense'],
        ]);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(['Courses' => 'groceries', 'Épargne & crypto' => 'savings', 'Animaux' => null], Category::pluck('key', 'name')->all());
    }

    public function test_a_configured_file_that_does_not_exist_is_skipped(): void
    {
        config(['finance.category_rules' => 'tests/Fixtures/absent.json']);

        $this->seed(CategorySeeder::class);

        $this->assertSame(22, Category::count());
        $this->assertFalse(CategoryRule::where('pattern', 'EMPLOYEUR EXEMPLE')->exists());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidRules(): array
    {
        return [
            'catégorie inconnue' => [['category' => 'Catégorie inexistante', 'pattern' => 'EXEMPLE']],
            'motif vide' => [['category' => 'Courses', 'pattern' => '  ']],
            'priorité non entière' => [['category' => 'Courses', 'pattern' => 'EXEMPLE', 'priority' => 'haute']],
        ];
    }

    /** @param array<string, mixed> $rule */
    #[DataProvider('invalidRules')]
    public function test_an_invalid_rule_is_rejected(array $rule): void
    {
        $path = tempnam(sys_get_temp_dir(), 'rules');
        file_put_contents($path, json_encode([$rule]));
        config(['finance.category_rules' => $path]); // chemin absolu

        try {
            $this->seed(CategorySeeder::class);
            $this->fail('Une règle invalide aurait dû être rejetée.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('règle n° 1', $exception->getMessage());
        } finally {
            unlink($path);
        }
    }
}
