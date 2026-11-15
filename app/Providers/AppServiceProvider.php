<?php

namespace App\Providers;

use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Les actions Livewire (/livewire/update) repassent aussi par ce contrôle,
        // pas seulement le chargement des pages.
        Livewire::addPersistentMiddleware([AuthenticateSession::class]);
    }
}
