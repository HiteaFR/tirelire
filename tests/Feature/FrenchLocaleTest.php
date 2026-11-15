<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

/** Pages clés en français (APP_LOCALE=fr) : le rendu d'avant la traduction est conservé. */
class FrenchLocaleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        Date::setTestNow('2026-09-20 10:00:00');
        $this->seed(TestCategorySeeder::class);
        $this->user = User::factory()->create();

        Transaction::create([
            'account_id' => Account::create(['name' => 'Compte courant'])->id,
            'category_id' => Category::where('key', 'groceries')->value('id'),
            'date' => '2026-09-11',
            'amount_cents' => -11000,
            'label' => 'PAIEMENT CB LECLERC',
            'balance_cents' => -50000,
            'source' => 'csv',
        ]);
    }

    public function test_dashboard(): void
    {
        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('<html lang="fr"', escape: false)
            ->assertSee('Patrimoine net')
            ->assertSee('Dernière opération le 11 sept. 2026')
            ->assertSee('Aucune opération depuis 9 jours — pense à importer ton relevé.')
            ->assertSee('Solde prévu fin septembre')
            ->assertSee('Dernière opération il y a 9 j')
            ->assertSee('1 opération ce mois')
            ->assertSee('Dépenses par catégorie')
            ->assertSee('Courses')
            ->assertSee('-110,00 €');
    }

    public function test_transactions_budgets_subscriptions_and_import(): void
    {
        $this->actingAs($this->user)->get('/operations')
            ->assertSee('1 opération')
            ->assertSee('vendredi 11 septembre')
            ->assertSee('Rechercher une opération…');

        $this->actingAs($this->user)->get('/budgets')
            ->assertSee('septembre 2026')
            ->assertSee('Fixe un plafond par catégorie.');

        $this->actingAs($this->user)->get('/abonnements')
            ->assertSee('Total récurrent')
            ->assertSee("Rien de détecté pour l'instant — importe d'abord un relevé CSV.");

        $this->actingAs($this->user)->get('/import')
            ->assertSee('Importer un relevé')
            ->assertSee('Formats reconnus automatiquement')
            ->assertSee('Profil → Relevés → Export');
    }

    public function test_login_page_and_artisan_output(): void
    {
        $this->get('/login')->assertSee('Se souvenir de moi')->assertSee('Se connecter');

        $this->artisan('finance:recategorize')->expectsOutputToContain('0 opération recatégorisée.');
    }
}
