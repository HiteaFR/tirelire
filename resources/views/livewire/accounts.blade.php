<div>
    <x-topbar :title="__('Accounts')">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="chip h-10 px-4">{{ __('Log out') }}</button>
        </form>
    </x-topbar>

    <div class="space-y-4">
        <a href="{{ route('categories') }}" wire:navigate class="card flex items-center gap-3 p-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface-2 text-lg" aria-hidden="true">🏷️</span>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">{{ __('Categories & rules') }}</p>
                <p class="text-[11px] text-ink-3">{{ __('Create categories and the rules that sort your operations') }}</p>
            </div>
            <svg class="size-4 shrink-0 text-ink-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </a>

        <div class="space-y-2">
            @foreach ($accounts as $account)
                @php $balance = $account->currentBalanceCents(); @endphp
                <div class="card flex items-center gap-3 p-4" wire:key="acc-{{ $account->id }}">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-ink-2">
                        {{ mb_strtoupper(mb_substr($account->bank ?: $account->name, 0, 2)) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $account->name }}</p>
                        <p class="text-[11px] text-ink-3">
                            {{ $account->bank ?? __('Bank not specified') }}
                            @if ($account->number) · {{ __('no. :number', ['number' => $account->number]) }} @endif
                            · {{ trans_choice(':count operation|:count operations', $account->transactions_count) }}
                        </p>
                        @if ($account->transactions_count === 0)
                            <button wire:click="delete({{ $account->id }})" wire:confirm="{{ __('Delete this account?') }}"
                                    class="mt-1 text-[11px] text-rose-400 underline decoration-dotted">{{ __('Delete') }}</button>
                        @endif
                    </div>
                    <x-money :cents="$balance" class="shrink-0 text-base font-semibold" />
                </div>
            @endforeach
        </div>

        <form wire:submit="save" class="card space-y-3 p-4">
            <p class="text-sm font-semibold">{{ __('Add an account') }}</p>
            <input type="text" wire:model="name" placeholder="{{ __('Name (e.g. Savings account)') }}" required class="field">
            <div class="grid grid-cols-2 gap-2">
                <input type="text" wire:model="bank" placeholder="{{ __('Bank') }}" class="field">
                <input type="text" wire:model="number" placeholder="{{ __('Account number') }}" class="field">
            </div>
            @error('name') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
            <button type="submit" class="btn-primary w-full">{{ __('Create') }}</button>
        </form>
    </div>
</div>
