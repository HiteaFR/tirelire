<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Login extends Component
{
    /** Tentatives ratées tolérées par adresse e-mail et IP avant blocage. */
    public const MAX_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 60;

    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate(['email' => 'required|email', 'password' => 'required']);

        $throttleKey = $this->throttleKey();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $this->reset('password');
            $this->addError('email', __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]));

            return;
        }

        if (! Auth::attempt(['email' => Str::lower($this->email), 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            // Le mot de passe ne repart pas vers le navigateur dans l'état du composant.
            $this->reset('password');
            $this->addError('email', __('auth.failed'));

            return;
        }

        RateLimiter::clear($throttleKey);
        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * E-mail + IP du client. L'IP est celle que résout TrustProxies : derrière
     * un proxy de confiance, celle du client ; sinon, celle de la connexion.
     */
    private function throttleKey(): string
    {
        return 'login:'.Str::transliterate(Str::lower(trim($this->email))).'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.login')->title(__('Login'));
    }
}
