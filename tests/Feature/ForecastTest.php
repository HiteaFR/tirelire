<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Account;
use App\Models\Category;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ForecastService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\Seeders\TestCategorySeeder;
use Tests\TestCase;

class ForecastTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-10-06 10:00:00');
        $this->seed(TestCategorySeeder::class);
        $this->account = Account::create(['name' => 'Compte courant']);
    }

    private function transaction(string $date, int $amountCents, string $merchant, ?string $category = null, ?int $balance = null): Transaction
    {
        return Transaction::create([
            'account_id' => $this->account->id,
            'category_id' => $category ? Category::where('name', $category)->value('id') : null,
            'date' => $date,
            'amount_cents' => $amountCents,
            'label' => $merchant,
            'merchant' => $merchant,
            'balance_cents' => $balance,
            'source' => 'csv',
        ]);
    }

    private function subscription(string $merchant, int $bucketCents, string $lastDate, string $status = 'active'): Subscription
    {
        return Subscription::create([
            'merchant' => $merchant,
            'label' => Subscription::prettyLabel($merchant),
            'bucket_cents' => $bucketCents,
            'avg_amount_cents' => $bucketCents,
            'last_date' => $lastDate,
            'occurrences' => 3,
            'status' => $status,
        ]);
    }

    /**
     * Juillet–septembre : salaire 2 000 €, loyer 700 €, 920 € de courses
     * (10 €/jour sur 92 jours), un virement d'épargne et Netflix.
     * Octobre : salaire et loyer passés, solde bancaire 100 € au 5 octobre,
     * donc −1 200 € au 30 septembre.
     */
    private function seedOverdraftScenario(): void
    {
        foreach (['07' => 30000, '08' => 32000, '09' => 30000] as $month => $groceries) {
            $this->transaction("2026-$month-02", 200000, 'ACME', 'Salaire');
            $this->transaction("2026-$month-05", -70000, 'LOYER', 'Logement');
            $this->transaction("2026-$month-01", -$groceries, 'LECLERC', 'Courses');
        }
        $this->transaction('2026-08-20', -50000, 'LIVRET A', 'Épargne & crypto');
        $this->transaction('2026-09-28', -1000, 'NETFLIX', 'Abonnements & télécom');
        $this->transaction('2026-10-02', 200000, 'ACME', 'Salaire');
        $this->transaction('2026-10-05', -70000, 'LOYER', 'Logement', 10000);

        $this->subscription('LOYER', 70000, '2026-10-05');
        $this->subscription('NETFLIX', 1000, '2026-09-28');
    }

    public function test_forecast_starts_from_the_real_balance_and_schedules_dated_charges(): void
    {
        $this->seedOverdraftScenario();

        $forecast = app(ForecastService::class)->forecast();

        $this->assertSame('2026-10-05', $forecast['anchor']->format('Y-m-d'));
        $this->assertSame(10000, $forecast['balance']);
        $this->assertSame(-120000, $forecast['start_balance']);
        $this->assertSame(1000, $forecast['everyday_daily']); // ni loyer, ni Netflix, ni épargne
        $this->assertSame(['days' => 5, 'spent' => 0, 'expected' => 5000, 'ahead' => 5000], $forecast['pace']);
        $this->assertSame(['income' => 0, 'charges' => 1000, 'charges_count' => 1, 'everyday' => 26000, 'days' => 26], $forecast['remaining']);
        $this->assertSame(-17000, $forecast['month_end_balance']);  // 100 − 10 Netflix − 26 × 10
        $this->assertSame(103000, $forecast['month_result']);       // +1 300 réalisés − 270 à venir
        $this->assertSame(0, $forecast['safe_to_save']);
        $this->assertSame(['2026-11-01', -18000], [$forecast['low_point']['date']->format('Y-m-d'), $forecast['low_point']['balance']]);
        $this->assertSame('2026-11', $forecast['back_to_positive']->format('Y-m'));
        $this->assertSame(['income' => 200000, 'charges' => 71000, 'everyday' => 30417, 'margin' => 98583], $forecast['monthly']);
        $this->assertSame(
            ['10-28 Netflix -1000', '11-02 Salaire 200000', '11-05 Loyer -70000', '11-28 Netflix -1000'],
            array_map(fn (array $e) => $e['date']->format('m-d').' '.$e['label'].' '.$e['amount'], $forecast['upcoming']),
        );
    }

    public function test_late_stale_and_repriced_charges_and_irregular_incomes(): void
    {
        $this->transaction('2026-09-03', -1800, 'ORANGE');
        $this->transaction('2026-08-20', -999, 'OLDSUB');
        $this->transaction('2026-07-01', -1500, 'GONE');
        $this->transaction('2026-09-08', -2000, 'ASSURANCE');
        $this->transaction('2026-10-01', -2600, 'ASSURANCE');
        $this->transaction('2026-10-02', 50000, 'SASU', 'Revenus freelance');
        $this->transaction('2026-10-05', -500, 'LECLERC', 'Courses', 0);
        $this->subscription('ORANGE', 1800, '2026-09-03');
        $this->subscription('OLDSUB', 999, '2026-08-20');
        $this->subscription('ASSURANCE', 2000, '2026-09-08');
        $this->subscription('GONE', 1500, '2026-07-01'); // plus prélevé depuis 96 jours
        $this->subscription('CANCELLED', 500, '2026-09-10', 'cancelled');

        $upcoming = collect(app(ForecastService::class)->forecast()['upcoming'])
            ->map(fn (array $e) => $e['date']->format('m-d').' '.$e['label'].' '.$e['amount'].($e['late'] ? ' late' : ''))
            ->all();

        $this->assertSame([
            '10-06 Orange -1800 late',  // attendu le 3, pas encore vu : imminent
            '10-20 Oldsub -999',        // septembre sauté, octobre attendu
            '11-01 Assurance -2600',    // nouveau tarif, date du dernier débit
            '11-03 Orange -1800',
            '11-20 Oldsub -999',
        ], $upcoming);
    }

    public function test_month_pace_compares_everyday_spending_to_date_with_the_average(): void
    {
        $this->seedOverdraftScenario();
        $this->transaction('2026-10-03', -7000, 'LECLERC', 'Courses');
        $this->transaction('2026-10-04', -1000, 'NETFLIX', 'Abonnements & télécom'); // échéance, pas courant
        $this->transaction('2026-10-04', -20000, 'LIVRET A', 'Épargne & crypto');    // virement, pas courant

        $this->assertSame(
            ['days' => 5, 'spent' => 7000, 'expected' => 5000, 'ahead' => -2000],
            app(ForecastService::class)->forecast()['pace'],
        );
        Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->assertSee('Month pace')
            ->assertSee('20,00 € behind');
    }

    /** Régression : en UTC, « aujourd'hui » retardait d'un jour entre minuit et 2 h, heure de Paris. */
    public function test_today_follows_the_configured_timezone_just_after_midnight(): void
    {
        $defaultTimezone = date_default_timezone_get();
        config(['app.timezone' => 'Europe/Paris']);
        date_default_timezone_set('Europe/Paris');
        Date::setTestNow(CarbonImmutable::parse('2026-10-06 00:30', 'Europe/Paris')); // 22:30 UTC la veille

        try {
            $this->transaction('2026-10-06', -7000, 'LECLERC', 'Courses', 10000);

            $forecast = app(ForecastService::class)->forecast();

            $this->assertSame('2026-10-06', $forecast['anchor']->format('Y-m-d'));
            $this->assertSame([6, 7000], [$forecast['pace']['days'], $forecast['pace']['spent']]); // l'opération du jour compte dans le rythme
        } finally {
            date_default_timezone_set($defaultTimezone);
        }
    }

    public function test_dashboard_shows_forecast_for_current_month_and_review_for_past_month(): void
    {
        $this->seedOverdraftScenario();

        $component = Livewire::actingAs(User::factory()->create())->test(Dashboard::class)
            ->assertSee('Expected balance end of October')
            ->assertSee('-170,00 €')
            ->assertSee('Nothing to put aside')
            ->assertSee('Back to positive estimated end of November 2026')
            ->assertSee('10,00 €/d × 26 d')
            ->assertSee('50,00 € ahead');

        $component->call('previousMonth')
            ->assertSee('Review of September')
            ->assertSee('-1 200,00 €')
            ->assertDontSee('Expected balance');
    }
}
