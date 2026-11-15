<?php

namespace Tests\Feature;

use App\Livewire\Subscriptions;
use App\Livewire\Transactions;
use App\Models\Account;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MerchantNormalizer;
use App\Services\SubscriptionDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionMarkingTest extends TestCase
{
    use RefreshDatabase;

    private Account $account;

    protected function setUp(): void
    {
        parent::setUp();
        Date::setTestNow('2026-09-20 10:00:00');
        $this->account = Account::create(['name' => 'Compte courant']);
    }

    private function debit(string $date, int $cents, string $label): Transaction
    {
        return Transaction::create([
            'account_id' => $this->account->id,
            'date' => $date,
            'amount_cents' => -$cents,
            'label' => $label,
            'merchant' => app(MerchantNormalizer::class)->normalize($label),
            'source' => 'csv',
        ]);
    }

    public function test_paypal_debits_are_split_into_one_subscription_per_amount(): void
    {
        foreach (['2026-04-23', '2026-05-25', '2026-06-23', '2026-07-23', '2026-08-25'] as $d) {
            $this->debit($d, 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000001/PAYPAL');
        }
        foreach (['2026-04-08', '2026-06-22', '2026-07-21', '2026-08-20'] as $d) {
            $this->debit($d, $d < '2026-06' ? 1250 : 1200, 'PRLV SEPA PAYPAL (EUROPE) S A R 1000000000002 PAYPAL');
        }
        $this->debit('2026-05-02', 4500, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000003/PAYPAL'); // achat isolé

        app(SubscriptionDetector::class)->detect();

        $subs = Subscription::orderBy('bucket_cents')->get();
        $this->assertCount(2, $subs);
        $this->assertSame(['PAYPAL', 'PAYPAL'], $subs->pluck('merchant')->all());
        $this->assertSame([799, 1200], $subs->pluck('bucket_cents')->all()); // médiane du palier
        $this->assertSame([5, 4], $subs->pluck('occurrences')->all());
    }

    public function test_a_price_change_opens_a_new_bucket_and_the_old_one_is_purged_once_stale(): void
    {
        foreach (['2025-12-29', '2026-01-27', '2026-02-27'] as $d) {
            $this->debit($d, 2100, 'PAIEMENT CB 2612 20 00 USD OPENAI *CHATGPT CARTE 0000 UIR000012345678905');
        }
        foreach (['2026-06-29', '2026-07-27', '2026-08-27'] as $d) {
            $this->debit($d, 900, 'PAIEMENT CB 2603 9 00 USD OPENAI *CHATGPT CARTE 0000 UIR000012345678906');
        }

        app(SubscriptionDetector::class)->detect();

        $this->assertSame(1, Subscription::count());
        $this->assertSame(900, Subscription::first()->bucket_cents);
        $this->assertSame('Openai *Chatgpt', Subscription::first()->label);
    }

    public function test_a_price_increase_continues_the_same_subscription_at_the_new_price(): void
    {
        $this->debit('2025-10-08', 19200, 'PRLV SEPA ECOLE EXEMPLE FACTURE ANNUELLE'); // rentrée précédente, hors série
        foreach (['2025-11-06', '2025-12-08', '2026-01-06', '2026-02-06', '2026-03-06', '2026-04-08', '2026-05-07', '2026-06-08'] as $d) {
            $this->debit($d, 12000, 'PRLV SEPA ECOLE EXEMPLE FACTURE ANNUELLE');
        }
        $this->debit('2026-07-15', 20500, 'PRLV SEPA ECOLE EXEMPLE FACTURE ANNUELLE');
        $this->debit('2026-09-07', 26000, 'PRLV SEPA ECOLE EXEMPLE FACTURE ANNUELLE'); // août sauté, nouveau tarif
        $renamed = Subscription::create([
            'merchant' => 'ECOLE EXEMPLE', 'bucket_cents' => 12000, 'label' => 'École',
            'avg_amount_cents' => 12000, 'last_date' => '2026-06-08', 'occurrences' => 8,
        ]);

        app(SubscriptionDetector::class)->detect();

        $sub = Subscription::sole();
        $this->assertTrue($sub->is($renamed));
        $this->assertSame('École', $sub->label);
        $this->assertSame('monthly', $sub->period);
        $this->assertSame(26000, $sub->bucket_cents);
        $this->assertSame(26000, $sub->avg_amount_cents);
        $this->assertSame(10, $sub->occurrences);
        $this->assertSame('2026-09-07', $sub->last_date->format('Y-m-d'));
    }

    public function test_a_one_off_month_does_not_cut_the_series_in_two(): void
    {
        foreach (['2026-03-06', '2026-04-07', '2026-05-06', '2026-06-08', '2026-08-06', '2026-09-07'] as $d) {
            $this->debit($d, 1500, 'PRLV SEPA ORANGE SA VOTRE ABONNEMENT MOBILE: 07XXXX');
        }
        $this->debit('2026-07-06', 2500, 'PRLV SEPA ORANGE SA VOTRE ABONNEMENT MOBILE: 07XXXX'); // hors forfait

        app(SubscriptionDetector::class)->detect();

        $sub = Subscription::sole();
        $this->assertSame(1500, $sub->bucket_cents);
        $this->assertSame(6, $sub->occurrences);
    }

    public function test_a_cancelled_subscription_charged_again_is_reactivated_with_an_alert(): void
    {
        $label = 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000001/PAYPAL';
        foreach (['2026-06-23', '2026-07-23', '2026-08-25'] as $d) {
            $this->debit($d, 799, $label);
        }
        app(SubscriptionDetector::class)->detect();
        $sub = Subscription::sole();
        $user = User::factory()->create();
        $page = Livewire::actingAs($user)->test(Subscriptions::class)->call('setStatus', $sub->id, 'cancelled');
        $this->assertSame(['cancelled', '2026-09-20'], [$sub->fresh()->status, $sub->fresh()->cancelled_at->format('Y-m-d')]);

        $this->travelTo('2026-10-25');
        $this->debit('2026-10-23', 799, $label);
        app(SubscriptionDetector::class)->detect();

        $this->assertSame(['active', '2026-10-23'], [$sub->fresh()->status, $sub->fresh()->resumed_on->format('Y-m-d')]);
        $this->actingAs($user)->get('/')->assertSeeInOrder(['Paypal', 'was charged', 'after cancellation']);
        $page = Livewire::actingAs($user)->test(Subscriptions::class)
            ->assertSeeInOrder(['was charged on', 'Oct 23, 2026', 'although it'])
            ->call('keepCancelled', $sub->id);

        // Résiliation ratée : il repart en résilié et la même opération ne réalerte pas
        app(SubscriptionDetector::class)->detect();
        $this->assertSame(['cancelled', '2026-10-25', null], [$sub->fresh()->status, $sub->fresh()->cancelled_at->format('Y-m-d'), $sub->fresh()->resumed_on]);

        $this->travelTo('2026-11-25');
        $this->debit('2026-11-23', 799, $label);
        app(SubscriptionDetector::class)->detect();
        $page->call('confirmResumed', $sub->id)->assertDontSee('Still cancelled');

        $this->assertSame(['active', null, null], [$sub->fresh()->status, $sub->fresh()->cancelled_at, $sub->fresh()->resumed_on]);
    }

    public function test_a_subscription_marked_as_not_one_is_never_reactivated(): void
    {
        foreach (['2026-06-23', '2026-07-23', '2026-08-25'] as $d) {
            $this->debit($d, 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000001/PAYPAL');
        }
        app(SubscriptionDetector::class)->detect();
        Livewire::actingAs(User::factory()->create())->test(Subscriptions::class)->call('setStatus', Subscription::sole()->id, 'ignored');

        $this->debit('2026-09-19', 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000001/PAYPAL');
        app(SubscriptionDetector::class)->detect();

        $this->assertSame('ignored', Subscription::sole()->status);
    }

    public function test_two_similar_purchases_are_not_a_subscription(): void
    {
        $this->debit('2026-07-20', 4500, 'PAIEMENT CB 2007 VILLE LIDL 1234 CARTE 0000 UIR000020');
        $this->debit('2026-08-19', 4700, 'PAIEMENT CB 1908 VILLE LIDL 1234 CARTE 0000 UIR000023');

        app(SubscriptionDetector::class)->detect();

        $this->assertSame(0, Subscription::count());
    }

    public function test_an_operation_can_be_marked_as_subscription_from_the_transactions_page(): void
    {
        $older = $this->debit('2026-08-25', 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000004/PAYPAL');
        $latest = $this->debit('2026-09-15', 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000005/PAYPAL');
        $this->debit('2026-09-10', 1200, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000006/PAYPAL'); // autre palier

        $component = Livewire::actingAs(User::factory()->create())->test(Transactions::class)
            ->call('open', $latest->id)
            ->assertSet('selectedId', $latest->id)
            ->assertSet('subscriptionLabel', 'Paypal')
            ->assertSee('Mark as subscription')
            ->set('subscriptionLabel', 'Netflix')
            ->call('markAsSubscription', $latest->id)
            ->assertHasNoErrors()
            ->assertSee('🔁 Netflix');

        $sub = Subscription::sole();
        $this->assertSame('Netflix', $sub->label);
        $this->assertSame('manual', $sub->source);
        $this->assertSame(799, $sub->bucket_cents);
        $this->assertSame(2, $sub->occurrences); // l'opération d'août est rattachée
        $this->assertTrue($sub->matches($older));

        // Une redétection garde le nom et l'entrée manuelle, même sous le seuil auto
        app(SubscriptionDetector::class)->detect();
        $this->assertSame('Netflix', $sub->fresh()->label);
        $this->assertSame(1, Subscription::count());

        $component->call('unmarkSubscription', $latest->id)
            ->assertDontSee('🔁 Netflix')
            ->assertSee('Mark as subscription');
        $this->assertSame('ignored', $sub->fresh()->status);

        // Re-marquer réactive la même entrée au lieu d'en créer une seconde
        $component->set('subscriptionLabel', 'Netflix')->call('markAsSubscription', $latest->id)->assertHasNoErrors();
        $this->assertSame(1, Subscription::count());
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_a_subscription_can_be_renamed_and_the_name_survives_redetection(): void
    {
        foreach (['2026-06-23', '2026-07-23', '2026-08-25'] as $d) {
            $this->debit($d, 799, 'PRLV SEPA PAYPAL EUROPE S A R L 1000000000001/PAYPAL');
        }
        app(SubscriptionDetector::class)->detect();
        $sub = Subscription::sole();

        Livewire::actingAs(User::factory()->create())->test(Subscriptions::class)
            ->call('rename', $sub->id, '  Netflix ')
            ->assertSee('Netflix')
            ->assertSee('Paypal ·');

        app(SubscriptionDetector::class)->detect();

        $this->assertSame('Netflix', $sub->fresh()->label);
    }
}
