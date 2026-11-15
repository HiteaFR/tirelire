<?php

use App\Livewire\Accounts;
use App\Livewire\Budgets;
use App\Livewire\Categories;
use App\Livewire\Dashboard;
use App\Livewire\ImportCsv;
use App\Livewire\Login;
use App\Livewire\Subscriptions;
use App\Livewire\Transactions;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', Login::class)->name('login');

Route::post('/logout', function () {
    Auth::logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

// AuthenticateSession : une session ouverte avant un changement de mot de
// passe (finance:user) est déconnectée à sa requête suivante.
Route::middleware(['auth', AuthenticateSession::class])->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/operations', Transactions::class)->name('transactions');
    Route::get('/import', ImportCsv::class)->name('import');
    Route::get('/abonnements', Subscriptions::class)->name('subscriptions');
    Route::get('/budgets', Budgets::class)->name('budgets');
    Route::get('/comptes', Accounts::class)->name('accounts');
    Route::get('/categories', Categories::class)->name('categories');
});
