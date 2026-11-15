<?php

namespace App\Livewire;

use App\Livewire\Concerns\TogglesPrivateMode;
use App\Models\Account;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app')]
class Accounts extends Component
{
    use TogglesPrivateMode;

    public string $name = '';

    public string $bank = '';

    public string $number = '';

    public function save(): void
    {
        $this->validate(['name' => 'required|string|max:100']);

        Account::create([
            'name' => $this->name,
            'bank' => $this->bank ?: null,
            'number' => $this->number ?: null,
        ]);

        $this->reset('name', 'bank', 'number');
    }

    public function delete(Account $account): void
    {
        $account->delete();
    }

    public function render()
    {
        return view('livewire.accounts', [
            'accounts' => Account::withCount('transactions')->get(),
        ])->title(__('Accounts'));
    }
}
