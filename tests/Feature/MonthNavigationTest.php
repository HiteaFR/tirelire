<?php

namespace Tests\Feature;

use App\Livewire\Budgets;
use App\Livewire\Dashboard;
use App\Livewire\Transactions;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class MonthNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-20 10:00:00');
        $this->user = User::factory()->create();
    }

    public function test_selected_month_is_shared_across_pages(): void
    {
        $this->actingAs($this->user)->get('/?month=2026-07')->assertOk()->assertSee('July 2026');

        $this->actingAs($this->user)->get('/operations')->assertOk()->assertSee('July 2026');
        $this->actingAs($this->user)->get('/budgets')->assertOk()->assertSee('July 2026');

        // Une URL explicite reprend la main sur la session…
        $this->actingAs($this->user)->get('/operations?month=2026-05')->assertOk()->assertSee('May 2026');
        // …et devient à son tour le mois partagé.
        $this->actingAs($this->user)->get('/')->assertOk()->assertSee('May 2026');
    }

    public function test_month_picker_jumps_directly_and_rejects_invalid_or_future_months(): void
    {
        Livewire::actingAs($this->user)->test(Dashboard::class)
            ->call('selectMonth', '2026-03')
            ->assertSet('month', '2026-03')
            ->assertSee('March 2026')
            ->call('selectMonth', '2027-01')
            ->assertSet('month', '2026-09')
            ->call('selectMonth', 'n-importe-quoi')
            ->assertSet('month', '2026-09');

        $this->assertSame('2026-09', session('finance.month'));
    }

    public function test_budgets_follow_the_selected_month(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Compte courant']);
        $courses = Category::where('name', 'Courses')->value('id');
        Transaction::create(['account_id' => $account->id, 'category_id' => $courses, 'date' => '2026-09-10', 'amount_cents' => -12000, 'label' => 'LECLERC', 'source' => 'csv']);
        Transaction::create(['account_id' => $account->id, 'category_id' => $courses, 'date' => '2026-08-10', 'amount_cents' => -34000, 'label' => 'LIDL', 'source' => 'csv']);

        Livewire::actingAs($this->user)->test(Budgets::class)
            ->assertSee('September 2026')
            ->assertSee('120,00 €')
            ->call('previousMonth')
            ->assertSet('month', '2026-08')
            ->assertSee('340,00 €')
            ->call('nextMonth')
            ->assertSet('month', '2026-09')
            ->call('nextMonth')
            ->assertSet('month', '2026-09');
    }

    public function test_budget_total_only_counts_spending_of_budgeted_categories(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Compte courant']);
        $groceries = Category::where('key', 'groceries')->value('id');
        Budget::create(['category_id' => $groceries, 'amount_cents' => 30000]);
        Transaction::create(['account_id' => $account->id, 'category_id' => $groceries, 'date' => '2026-09-10', 'amount_cents' => -12000, 'label' => 'LECLERC', 'source' => 'csv']);
        Transaction::create(['account_id' => $account->id, 'category_id' => Category::where('key', 'housing')->value('id'), 'date' => '2026-09-05', 'amount_cents' => -80000, 'label' => 'LOYER', 'source' => 'csv']);

        Livewire::actingAs($this->user)->test(Budgets::class)
            ->assertSee('There is')
            ->assertSee('180,00 €')            // 300 − 120, le loyer sans budget n'entre pas dans le total
            ->assertDontSee('Overspent by');
    }

    public function test_transactions_page_sums_the_filtered_selection(): void
    {
        $this->seed(TestCategorySeeder::class);
        $account = Account::create(['name' => 'Compte courant']);
        Transaction::create(['account_id' => $account->id, 'date' => '2026-09-05', 'amount_cents' => 250000, 'label' => 'SALAIRE', 'source' => 'csv']);
        Transaction::create(['account_id' => $account->id, 'date' => '2026-09-06', 'amount_cents' => -80000, 'label' => 'LOYER', 'source' => 'csv']);
        Transaction::create(['account_id' => $account->id, 'date' => '2026-08-06', 'amount_cents' => -50000, 'label' => 'ANCIEN', 'source' => 'csv']);

        Livewire::actingAs($this->user)->test(Transactions::class)
            ->assertSee('2 operations')
            ->assertSee('2 500,00 €')
            ->assertSee('800,00 €')
            ->assertSee('+1 700,00 €')
            ->set('search', 'LOYER')
            ->assertSee('1 operation')
            ->assertSee('-800,00 €')
            ->assertDontSee('SALAIRE');
    }
}
