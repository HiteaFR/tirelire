<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Services\TransactionImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class PagesTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        return User::factory()->create();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/', '/operations', '/import', '/abonnements', '/budgets', '/comptes'] as $uri) {
            $this->get($uri)->assertRedirect('/login');
        }
    }

    public function test_all_pages_render_with_data(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Compte courant']);
        app(TransactionImporter::class)->import($account, implode("\n", [
            'Date;Date de valeur;Débit;Crédit;Libellé;Solde',
            now()->format('d/m/Y').';'.now()->format('d/m/Y').';-42,50;;PAIEMENT CB 2710 VILLE LIDL 1234 CARTE 0000 UIR000012345678904;957,50',
            now()->format('d/m/Y').';'.now()->format('d/m/Y').';;2000,00;VIR EMPLOYEUR EXEMPLE SALAIRE-1;2957,50',
        ]));

        $user = $this->actingUser();

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Net worth')->assertSee('Latest operations')->assertSee('LIDL');
        $this->actingAs($user)->get('/operations')->assertOk()->assertSee('LIDL');
        $this->actingAs($user)->get('/import')->assertOk()->assertSee('Start import');
        $this->actingAs($user)->get('/abonnements')->assertOk();
        $this->actingAs($user)->get('/budgets')->assertOk()->assertSee('Budgets');
        $this->actingAs($user)->get('/comptes')->assertOk()->assertSee('Compte courant');
    }

    public function test_dashboard_renders_with_empty_database(): void
    {
        $this->actingAs($this->actingUser())->get('/')
            ->assertOk()
            ->assertSee('No operation recorded — import a first statement')
            ->assertDontSee('Nothing to put aside')
            ->assertDontSee('Expected balance end of');
    }
}
