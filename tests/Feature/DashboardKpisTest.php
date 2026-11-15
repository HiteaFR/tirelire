<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StatsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class DashboardKpisTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-20 10:00:00');
        $this->seed(TestCategorySeeder::class);
        $this->account = Account::create(['name' => 'Compte courant']);
    }

    private function transaction(string $date, int $amountCents, string $label, ?string $category = null, ?int $balance = null): Transaction
    {
        return Transaction::create([
            'account_id' => $this->account->id,
            'category_id' => $category ? Category::where('name', $category)->value('id') : null,
            'date' => $date,
            'amount_cents' => $amountCents,
            'label' => $label,
            'balance_cents' => $balance,
            'source' => 'csv',
        ]);
    }

    public function test_last_transaction_is_the_most_recent_by_date_then_id(): void
    {
        $this->transaction('2026-09-11', -11000, 'LECLERC');
        $expected = $this->transaction('2026-09-11', -900, 'SPOTIFY');
        $this->transaction('2026-09-05', 250000, 'SALAIRE');

        $this->assertTrue(app(StatsService::class)->lastTransaction()->is($expected));
        $this->assertNull(app(StatsService::class)->lastTransaction($this->account->id + 1));
    }

    public function test_month_kpis_split_fixed_and_variable_and_compute_savings_rate(): void
    {
        $this->transaction('2026-09-05', 200000, 'SALAIRE', 'Salaire');
        $this->transaction('2026-09-06', -80000, 'LOYER', 'Logement');
        $this->transaction('2026-09-10', -20000, 'LECLERC', 'Courses');
        $this->transaction('2026-09-12', -50000, 'VIR LIVRET', 'Épargne & crypto'); // virement interne : exclu

        $kpis = app(StatsService::class)->monthKpis(CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(3, $kpis['count']);
        $this->assertSame(50, $kpis['savings_rate']);
        $this->assertSame(80000, $kpis['fixed_expense']);
        $this->assertSame(20000, $kpis['variable_expense']);
        $this->assertSame(1000, $kpis['avg_daily_variable']); // 200 € de variables / 20 jours écoulés
        $this->assertSame('LOYER', $kpis['biggest_expense']->label);
    }

    public function test_month_kpis_without_income_have_no_savings_rate(): void
    {
        $this->transaction('2026-08-10', -3000, 'CAFE', 'Bars & tabac');

        $kpis = app(StatsService::class)->monthKpis(CarbonImmutable::parse('2026-08-01'));

        $this->assertNull($kpis['savings_rate']);
        $this->assertSame(97, $kpis['avg_daily_variable']); // 30 € / 31 jours du mois complet
    }

    public function test_dashboard_highlights_last_transaction_and_warns_when_stale(): void
    {
        $this->transaction('2026-09-11', -11000, 'PAIEMENT CB LECLERC', 'Courses', -50000);

        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('Latest operations')
            ->assertSee('-110,00 €')
            ->assertSee('Last operation 9 d ago')
            ->assertSee('No operation for 9 days')
            ->assertSee('Last operation on')
            ->assertSee('Sep 11, 2026');
    }

    public function test_dashboard_cannot_navigate_past_the_current_month(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/?month=2026-12')->assertOk()->assertSee('September 2026')->assertDontSee('December 2026');
        $this->actingAs($user)->get('/operations?month=2026-12')->assertOk()->assertSee('September 2026');

        Livewire::actingAs($user)->test(Dashboard::class)
            ->assertSet('month', '2026-09')
            ->call('nextMonth')
            ->assertSet('month', '2026-09')
            ->call('previousMonth')
            ->assertSet('month', '2026-08')
            ->call('nextMonth')
            ->assertSet('month', '2026-09');
    }

    public function test_dashboard_aggregates_all_accounts_and_can_filter_one(): void
    {
        $n26 = Account::create(['name' => 'Compte Drill', 'bank' => 'N26']);
        $this->transaction('2026-09-11', -11000, 'LECLERC', 'Courses', -50000);
        Transaction::create([
            'account_id' => $n26->id, 'date' => '2026-09-20', 'amount_cents' => -600, 'label' => 'CAFE DU COMMERCE',
            'category_id' => Category::where('name', 'Bars & tabac')->value('id'), 'source' => 'csv',
        ]);

        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->assertSee('Net worth')
            ->assertSee('-506,00 €')            // −500,00 (CM, solde bancaire) + −6,00 (N26, somme)
            ->assertSee('2 accounts · last operation on')
            ->assertSee('Sep 20, 2026')
            ->assertSee('Compte Drill')
            ->assertSee('116,00 €');            // dépenses du mois, tous comptes

        $component->call('selectAccount', $n26->id)
            ->assertSet('account', $n26->id)
            ->assertSee('Balance Compte Drill')
            ->assertSee('-6,00 €')
            ->assertDontSee('-506,00 €')
            ->assertDontSee('LECLERC');
    }

    public function test_net_worth_of_a_past_month_is_the_balance_at_its_end(): void
    {
        $this->transaction('2026-08-20', -5000, 'LECLERC', 'Courses', 30000);
        $this->transaction('2026-09-02', 200000, 'SALAIRE', 'Salaire', 230000);
        $this->transaction('2026-09-11', -10000, 'LOYER', 'Logement', 220000);

        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->assertSee('2 200,00 €')
            ->assertSee('Last operation on');

        $component->call('previousMonth')
            ->assertSee('300,00 €')
            ->assertDontSee('2 200,00 €')
            ->assertSee('Balance on Aug 31, 2026');
    }
}
