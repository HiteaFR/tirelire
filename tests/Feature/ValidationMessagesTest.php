<?php

namespace Tests\Feature;

use App\Livewire\ImportCsv;
use App\Livewire\Transactions;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_messages_are_in_french_when_the_locale_is_fr(): void
    {
        app()->setLocale('fr');
        Account::create(['name' => 'Compte courant']);

        Livewire::actingAs(User::factory()->create())->test(ImportCsv::class)
            ->call('import')
            ->assertHasErrors(['file' => 'required'])
            ->assertSee('Le champ fichier CSV est obligatoire.');
    }

    public function test_english_messages_use_readable_field_names(): void
    {
        Account::create(['name' => 'Current account']);
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(ImportCsv::class)
            ->call('import')
            ->assertSee('The CSV file field is required.');

        Livewire::actingAs($user)->test(Transactions::class)
            ->set('showForm', true)->set('formLabel', '')->set('formAmount', '')
            ->call('save')
            ->assertSee('The label field is required.')
            ->assertSee('The amount field is required.');
    }
}
