<?php

namespace Tests\Feature;

use App\Livewire\Transactions;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Money;
use App\Support\PrivateMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

class PrivateModeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function lidlPurchase(): void
    {
        Transaction::create([
            'account_id' => Account::create(['name' => 'Compte courant'])->id,
            'date' => now()->toDateString(),
            'amount_cents' => -4250,
            'label' => 'LIDL',
            'source' => 'csv',
        ]);
    }

    public function test_amounts_are_visible_by_default(): void
    {
        $this->lidlPurchase();

        $this->actingAs($this->user)->get('/operations')
            ->assertSee('42,50 €')
            ->assertDontSee(Money::masked());
    }

    public function test_private_mode_cookie_masks_amounts_with_asterisks(): void
    {
        $this->lidlPurchase();

        $this->actingAs($this->user)->withCookie(PrivateMode::COOKIE, '1')->get('/operations')
            ->assertSee(Money::masked())
            ->assertDontSee('42,50');
    }

    public function test_private_mode_also_masks_the_monthly_net_of_the_six_month_chart(): void
    {
        Transaction::create([
            'account_id' => Account::create(['name' => 'Compte courant'])->id,
            'date' => now()->toDateString(),
            'amount_cents' => 123456,
            'label' => 'SALAIRE',
            'source' => 'csv',
        ]);

        // Une seule requête par test : PrivateMode est lié à la requête (Scoped).
        $this->actingAs($this->user)->withCookie(PrivateMode::COOKIE, '1')->get('/')
            ->assertSee('****')
            ->assertDontSee('1 235')
            ->assertDontSee('1 234');
    }

    public function test_the_chart_shows_the_monthly_net_without_private_mode(): void
    {
        Transaction::create([
            'account_id' => Account::create(['name' => 'Compte courant'])->id,
            'date' => now()->toDateString(),
            'amount_cents' => 123456,
            'label' => 'SALAIRE',
            'source' => 'csv',
        ]);

        $this->actingAs($this->user)->get('/')->assertSee('+1 235');
    }

    public function test_header_toggle_masks_amounts_immediately_and_remembers_the_choice(): void
    {
        $this->lidlPurchase();

        Livewire::actingAs($this->user)->test(Transactions::class)
            ->assertSee('42,50 €')
            ->call('togglePrivateMode')
            ->assertSee(Money::masked())
            ->assertDontSee('42,50');

        $cookie = Cookie::queued(PrivateMode::COOKIE);
        $this->assertSame('1', $cookie->getValue());
        // 365 jours exacts, comme PrivateMode : addYear() garde l'heure locale et
        // décale d'une heure entre deux changements d'heure (d'un jour avec un 29 février)
        $this->assertEqualsWithDelta(now()->addMinutes(60 * 24 * 365)->getTimestamp(), $cookie->getExpiresTime(), 60);
    }

    public function test_toggling_from_private_mode_reveals_amounts_and_updates_the_cookie(): void
    {
        $mode = app(PrivateMode::class);
        $mode->toggle();

        $revealed = $mode->toggle();

        $this->assertFalse($revealed);
        $this->assertFalse($mode->isEnabled());
        $this->assertSame('0', Cookie::queued(PrivateMode::COOKIE)->getValue());
        $this->assertSame('1,00 €', money(100));
    }
}
