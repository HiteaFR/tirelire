<?php

namespace Tests\Feature;

use App\Console\Commands\UserCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'un mot de passe solide';

    protected function tearDown(): void
    {
        putenv(UserCommand::PASSWORD_ENV);

        parent::tearDown();
    }

    public function test_it_creates_a_user_with_the_password_typed_twice(): void
    {
        $this->artisan('finance:user', ['email' => 'jean@example.com'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password confirmation', self::PASSWORD)
            ->expectsOutputToContain('User jean@example.com created.')
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame(['jean@example.com', 'jean'], [$user->email, $user->name]);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_resets_the_password_of_an_existing_user_and_revokes_remember_me(): void
    {
        $user = User::factory()->create(['email' => 'jean@example.com', 'name' => 'Jean', 'remember_token' => 'ancien-jeton']);

        $this->artisan('finance:user', ['email' => 'jean@example.com'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password confirmation', self::PASSWORD)
            ->expectsOutputToContain('Password of jean@example.com reset.')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(1, User::count());
        $this->assertSame('Jean', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotSame('ancien-jeton', $user->remember_token);
    }

    public function test_a_password_reset_logs_out_sessions_opened_before_it(): void
    {
        $user = User::factory()->create(['email' => 'jean@example.com']);
        Auth::login($user);
        $this->get('/')->assertOk();
        putenv(UserCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $this->artisan('finance:user', ['email' => 'jean@example.com', '--password-env' => true, '--no-interaction' => true])->assertSuccessful();
        Auth::forgetGuards(); // requête suivante : l'utilisateur est relu depuis la session et la base

        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_email_is_lowercased_so_a_reset_with_another_case_finds_the_same_user(): void
    {
        putenv(UserCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $this->artisan('finance:user', ['email' => 'Jean@Example.com', '--password-env' => true, '--no-interaction' => true])->assertSuccessful();
        $this->artisan('finance:user', ['email' => 'JEAN@example.com', '--password-env' => true, '--no-interaction' => true])
            ->expectsOutputToContain('reset.')
            ->assertSuccessful();

        $this->assertSame('jean@example.com', User::sole()->email);
    }

    public function test_it_reads_the_password_from_the_environment_for_automated_installs(): void
    {
        putenv(UserCommand::PASSWORD_ENV.'='.self::PASSWORD);

        $this->artisan('finance:user', ['email' => 'jean@example.com', '--name' => 'Jean Dupont', '--password-env' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $user = User::sole();
        $this->assertSame('Jean Dupont', $user->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_refuses_a_short_password(): void
    {
        $this->artisan('finance:user', ['email' => 'jean@example.com'])
            ->expectsQuestion('Password', 'court')
            ->expectsQuestion('Password confirmation', 'court')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_refuses_a_confirmation_that_does_not_match(): void
    {
        $this->artisan('finance:user', ['email' => 'jean@example.com'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password confirmation', self::PASSWORD.' bis')
            ->expectsOutputToContain('do not match')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_never_falls_back_to_a_default_password_when_none_is_given(): void
    {
        $this->artisan('finance:user', ['email' => 'jean@example.com', '--no-interaction' => true])
            ->expectsOutputToContain('Password required')
            ->assertFailed();

        $this->artisan('finance:user', ['email' => 'jean@example.com', '--password-env' => true, '--no-interaction' => true])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('finance:user', ['email' => 'pas-une-adresse'])
            ->expectsOutputToContain('Invalid email address')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
