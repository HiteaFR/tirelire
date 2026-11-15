<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Budget;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use RuntimeException;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-20 10:00:00');
    }

    public function test_it_fills_an_empty_database_with_categorized_history_subscriptions_and_budgets(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(['Main account', 'Savings account'], Account::orderBy('id')->pluck('name')->all());
        $this->assertGreaterThan(150, Transaction::count());
        $this->assertSame('2026-04-01', Transaction::orderBy('date')->first()->date->format('Y-m-d'));
        $this->assertSame('2026-09-20', Transaction::orderByDesc('date')->first()->date->format('Y-m-d'));
        $this->assertLessThan(5, Transaction::whereNull('category_id')->count());
        $this->assertSame(4, Budget::count());
        $this->assertEqualsCanonicalizing(
            ['LOYER RESIDENCE DES LILAS', 'EDF CLIENTS PARTICULIERS', 'FREE MOBILE', 'NETFLIX.COM', 'SPOTIFY'],
            Subscription::pluck('merchant')->all(),
        );
        $this->assertTrue(Subscription::where('merchant', 'like', 'NETFLIX%')->where('status', 'active')->exists());
        $this->assertTrue(Subscription::where('merchant', 'like', 'SPOTIFY%')->where('status', 'to_cancel')->exists());
    }

    public function test_every_page_renders_with_the_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk()->assertSee('Net worth')->assertSee('Month pace');
        $this->actingAs($user)->get('/operations')->assertOk()->assertSee('BOULANGERIE DU MARCHE');
        $this->actingAs($user)->get('/abonnements')->assertOk()->assertSee('Netflix');
        $this->actingAs($user)->get('/budgets')->assertOk()->assertSee('Groceries');
        $this->actingAs($user)->get('/categories')->assertOk()->assertSee('Groceries');
    }

    public function test_it_refuses_to_touch_a_database_that_already_has_operations(): void
    {
        Transaction::factory()->create();

        try {
            $this->seed(DemoSeeder::class);
            $this->fail('DemoSeeder should have refused a database that already contains operations.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already contains operations', $exception->getMessage());
        }

        $this->assertSame(1, Transaction::count());
        $this->assertSame(0, Budget::count());
    }

    public function test_the_default_database_seeder_never_seeds_the_demo(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Account::count());
        $this->assertSame(0, Transaction::count());
    }
}
