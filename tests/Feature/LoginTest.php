<?php

namespace Tests\Feature;

use App\Livewire\Login;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Livewire;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'un mot de passe solide';

    private function user(): User
    {
        return User::factory()->create(['email' => 'jean@example.com', 'password' => self::PASSWORD]);
    }

    public function test_a_user_logs_in_with_valid_credentials_whatever_the_email_case(): void
    {
        $user = $this->user();

        Livewire::test(Login::class)
            ->set('email', 'Jean@Example.com')
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::user()->is($user));
    }

    public function test_attempts_are_blocked_after_five_failures_then_allowed_again(): void
    {
        app()->setLocale('fr');
        $this->user();
        $login = Livewire::test(Login::class)->set('email', 'jean@example.com');

        foreach (range(1, Login::MAX_ATTEMPTS) as $attempt) {
            $login->set('password', 'mauvais')->call('login')->assertHasErrors('email')->assertSee('Identifiants incorrects.')->assertSet('password', '');
        }

        // Même le bon mot de passe est refusé tant que le blocage dure.
        $login->set('password', self::PASSWORD)->call('login')
            ->assertSee('Trop de tentatives de connexion. Réessayez dans')
            ->assertSet('password', '')
            ->assertNoRedirect();
        $this->assertGuest();

        $this->travel(Login::LOCKOUT_SECONDS + 1)->seconds();

        $login->set('password', self::PASSWORD)->call('login')->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_the_block_is_per_email_so_another_account_can_still_log_in(): void
    {
        $this->user();
        $other = User::factory()->create(['email' => 'marie@example.com', 'password' => self::PASSWORD]);
        $login = Livewire::test(Login::class)->set('email', 'jean@example.com');
        foreach (range(1, Login::MAX_ATTEMPTS) as $attempt) {
            $login->set('password', 'mauvais')->call('login');
        }
        $login->set('password', self::PASSWORD)->call('login')->assertNoRedirect(); // jean est bloqué

        Livewire::test(Login::class)->set('email', 'marie@example.com')->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertTrue(Auth::user()->is($other));
    }

    public function test_remember_me_is_off_unless_the_box_is_checked(): void
    {
        $this->user();

        Livewire::test(Login::class)
            ->assertSet('remember', false)
            ->set('email', 'jean@example.com')
            ->set('password', self::PASSWORD)
            ->call('login');

        $this->assertFalse(Cookie::hasQueued(Auth::guard()->getRecallerName()));
    }

    public function test_remember_me_sets_the_long_lived_cookie_when_checked(): void
    {
        $this->user();

        Livewire::test(Login::class)
            ->set('email', 'jean@example.com')
            ->set('password', self::PASSWORD)
            ->set('remember', true)
            ->call('login');

        $this->assertTrue(Cookie::hasQueued(Auth::guard()->getRecallerName()));
    }
}
