<div>
    <x-topbar :month-date="$monthDate" :can-go-next="$canGoNext" :months="$months">
        @if ($accounts->count() > 1)
            @php $currentAccount = $account ? $accounts->firstWhere('id', $account) : null; @endphp
            {{-- Sélecteur de compte : menu déroulant, « Tous » par défaut --}}
            <div x-data="{ open: false }" class="relative" x-on:keydown.escape.window="open = false">
                <button type="button" x-on:click="open = !open" aria-haspopup="true" :aria-expanded="open"
                        aria-label="{{ $currentAccount ? __('Account: :account', ['account' => $currentAccount->bank ?: $currentAccount->name]) : __('All accounts') }}"
                        class="chip h-10 max-w-36 pl-2.5 pr-2 text-sm {{ $currentAccount ? 'chip-on' : '' }}">
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a2.25 2.25 0 00-2.25-2.25H15a3 3 0 11-6 0H5.25A2.25 2.25 0 003 12m18 0v6a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 18v-6m18 0V9M3 12V9m18 0a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 9m18 0V6a2.25 2.25 0 00-2.25-2.25H5.25A2.25 2.25 0 003 6v3"/></svg>
                    {{-- Sur téléphone, le header n'a pas la place pour le nom : l'état « chip-on » signale le filtre --}}
                    <span class="hidden truncate min-[480px]:inline">{{ $currentAccount ? ($currentAccount->bank ?: $currentAccount->name) : __('All') }}</span>
                    <svg class="size-3.5 shrink-0 opacity-70 transition" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                </button>
                <div x-cloak x-show="open" x-transition.origin.top.right x-on:click.outside="open = false"
                     class="absolute right-0 top-12 z-40 w-64 rounded-3xl bg-surface-2 p-1.5 shadow-2xl shadow-black/60 ring-1 ring-white/10">
                    <button type="button" wire:click="$set('account', null)" x-on:click="open = false"
                            class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-white/5 {{ ! $account ? 'bg-white/10 font-semibold' : 'text-ink-2' }}">
                        <span class="grid size-8 shrink-0 place-items-center rounded-full bg-brand/15 text-brand">
                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>
                        </span>
                        <span class="flex-1">{{ __('All accounts') }}</span>
                        @if (! $account) <span class="text-brand">✓</span> @endif
                    </button>
                    @foreach ($accounts as $acc)
                        <button type="button" wire:click="$set('account', {{ $acc->id }})" x-on:click="open = false" wire:key="acc-opt-{{ $acc->id }}"
                                class="flex w-full items-center gap-3 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-white/5 {{ $account === $acc->id ? 'bg-white/10 font-semibold' : 'text-ink-2' }}">
                            <span class="grid size-8 shrink-0 place-items-center rounded-full bg-surface text-[11px] font-bold text-ink-2">
                                {{ mb_strtoupper(mb_substr($acc->bank ?: $acc->name, 0, 2)) }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{ $acc->name }}</span>
                                @if ($acc->bank) <span class="block truncate text-[11px] text-ink-3">{{ $acc->bank }}</span> @endif
                            </span>
                            @if ($account === $acc->id) <span class="text-brand">✓</span> @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
        <button wire:click="$toggle('showForm')" aria-label="{{ $showForm ? __('Close') : __('Add an operation') }}"
                class="grid size-10 shrink-0 place-items-center rounded-full transition {{ $showForm ? 'bg-surface text-ink-2 ring-1 ring-white/10' : 'bg-brand text-ground' }}">
            <svg class="size-5 transition {{ $showForm ? 'rotate-45' : '' }}" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        </button>
    </x-topbar>

    <div class="space-y-4">
        {{-- Formulaire d'ajout manuel --}}
        @if ($showForm)
            <form wire:submit="save" class="card space-y-3 p-4">
                <p class="text-sm font-semibold">{{ __('New operation') }}</p>
                <div class="grid grid-cols-2 gap-1 rounded-2xl bg-surface-2 p-1">
                    <button type="button" wire:click="$set('formType', 'expense')"
                            class="rounded-xl py-2 text-sm font-semibold transition {{ $formType === 'expense' ? 'bg-rose-400/20 text-rose-300' : 'text-ink-3' }}">
                        − {{ __('Expense') }}
                    </button>
                    <button type="button" wire:click="$set('formType', 'income')"
                            class="rounded-xl py-2 text-sm font-semibold transition {{ $formType === 'income' ? 'bg-brand/20 text-brand' : 'text-ink-3' }}">
                        + {{ __('Revenue') }}
                    </button>
                </div>
                <input type="text" wire:model="formLabel" placeholder="{{ __('Label (e.g. Bakery)') }}" class="field">
                <div class="grid grid-cols-2 gap-2">
                    <input type="text" inputmode="decimal" wire:model="formAmount" placeholder="{{ __('Amount :symbol', ['symbol' => config('finance.currency_symbol')]) }}" class="field">
                    <input type="date" wire:model="formDate" class="field">
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <select wire:model="formCategory" class="field">
                        <option value="">{{ __('Category…') }}</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->icon }} {{ $cat->name }}</option>
                        @endforeach
                    </select>
                    <select wire:model="formAccount" class="field">
                        @foreach ($accounts as $acc)
                            <option value="{{ $acc->id }}">{{ $acc->name }}</option>
                        @endforeach
                    </select>
                </div>
                @error('formLabel') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                @error('formAmount') <p class="text-xs text-rose-400">{{ $message }}</p> @enderror
                <button type="submit" class="btn-primary w-full">{{ __('Save') }}</button>
            </form>
        @endif

        {{-- Résumé de la sélection --}}
        <section class="card flex items-center justify-between px-4 py-3">
            <div>
                <p class="text-[11px] text-ink-3">{{ trans_choice('{0} :count operations|{1} :count operation|[2,*] :count operations', $count) }}</p>
                <p class="text-sm font-semibold">{{ __('Balance') }} <x-money :cents="$income - $expense" sign /></p>
            </div>
            <div class="flex gap-4 text-right text-xs">
                <div>
                    <p class="text-ink-3">{{ __('In') }}</p>
                    <p class="num font-semibold text-brand">{{ money($income) }}</p>
                </div>
                <div>
                    <p class="text-ink-3">{{ __('Out') }}</p>
                    <p class="num font-semibold">{{ money($expense) }}</p>
                </div>
            </div>
        </section>

        {{-- Filtres --}}
        <div class="space-y-3 pb-1">
            <div class="relative">
                <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-ink-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search an operation…') }}" class="field pl-9">
            </div>
            <select wire:model.live="category" class="chip max-w-full pr-7 {{ $category !== null ? 'chip-on' : '' }}">
                <option value="">{{ __('All categories') }}</option>
                <option value="0">❓ {{ __('Uncategorized') }}</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->icon }} {{ $cat->name }}</option>
                @endforeach
            </select>
        </div>

        {{-- Liste groupée par jour --}}
        <div class="space-y-4" wire:loading.class="opacity-60" wire:target="search,category,account,previousMonth,nextMonth,selectMonth">
            @forelse ($transactions->groupBy(fn ($t) => $t->date->format('Y-m-d')) as $day => $dayTxs)
                <section wire:key="day-{{ $day }}">
                    <div class="mb-1.5 flex items-baseline justify-between px-1">
                        <p class="text-xs font-semibold capitalize text-ink-2">{{ $dayTxs->first()->date->isoFormat('dddd D MMMM') }}</p>
                        <x-money :cents="$dayTxs->sum('amount_cents')" sign tone="neutral" class="text-[11px] text-ink-3" />
                    </div>
                    <div class="card divide-y divide-white/5">
                        @foreach ($dayTxs as $tx)
                            @php $sub = $subscriptionByTransaction[$tx->id] ?? null; @endphp
                            <div class="flex cursor-pointer items-center gap-3 px-3 py-2.5 transition active:bg-white/5"
                                 wire:key="tx-{{ $tx->id }}" wire:click="open({{ $tx->id }})">
                                <x-category-icon :category="$tx->category" />
                                <div class="min-w-0 flex-1">
                                    <p class="flex items-center gap-1.5 text-sm font-medium">
                                        <span class="truncate">{{ Str::limit($tx->merchant ?: $tx->label, 40) }}</span>
                                        @if ($sub)
                                            <span class="shrink-0 rounded-full bg-brand/15 px-1.5 py-0.5 text-[10px] font-semibold text-brand" title="{{ __('Subscription: :name', ['name' => $sub->label]) }}">🔁 {{ Str::limit($sub->label, 14) }}</span>
                                        @endif
                                    </p>
                                    <select wire:change="setCategory({{ $tx->id }}, $event.target.value)" x-on:click.stop aria-label="{{ __('Category') }}"
                                            class="mt-0.5 w-full max-w-[220px] border-0 bg-transparent p-0 pr-5 text-xs text-ink-3 focus:outline-none"
                                            style="background-position: right 0.25rem center; background-size: 0.75rem">
                                        <option value="0" @selected(! $tx->category_id)>{{ __('Uncategorized') }}</option>
                                        @foreach ($categories as $cat)
                                            <option value="{{ $cat->id }}" @selected($tx->category_id === $cat->id)>{{ $cat->icon }} {{ $cat->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="num text-sm font-semibold {{ $tx->amount_cents > 0 ? 'text-brand' : '' }}">{{ money($tx->amount_cents, sign: true) }}</p>
                                    @if ($accounts->count() > 1 && ! $account)
                                        <p class="text-[10px] text-ink-3">{{ $tx->account->bank ?: $tx->account->name }}</p>
                                    @elseif ($tx->source === 'manual')
                                        <p class="text-[10px] text-ink-3">{{ __('manual') }}</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="card p-8 text-center">
                    <p class="text-3xl">🍃</p>
                    <p class="mt-2 text-sm text-ink-2">{{ __('No operation in this period.') }}</p>
                </div>
            @endforelse
        </div>

        {{ $transactions->links() }}

        {{-- Fiche d'une opération : catégorie, abonnement, suppression --}}
        @if ($selected)
            <div class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm" wire:key="sheet-{{ $selected->id }}"
                 x-data x-on:keydown.escape.window="$wire.close()">
                <button type="button" class="absolute inset-0 cursor-default" wire:click="close" aria-label="{{ __('Close') }}"></button>
                <div class="relative w-full max-w-lg rounded-t-3xl bg-surface p-5 ring-1 ring-white/10" style="padding-bottom: calc(1.25rem + env(safe-area-inset-bottom))">
                    <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-white/15"></div>

                    <div class="flex items-start gap-3">
                        <x-category-icon :category="$selected->category" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-base font-semibold">{{ $selected->merchant ?: Str::limit($selected->label, 40) }}</p>
                            <p class="text-xs text-ink-3">
                                {{ $selected->date->isoFormat('dddd LL') }} · {{ $selected->account->bank ?: $selected->account->name }}
                            </p>
                            @if ($selected->merchant && $selected->merchant !== mb_strtoupper($selected->label))
                                <p class="mt-1 break-words text-[11px] text-ink-3">{{ $selected->label }}</p>
                            @endif
                        </div>
                        <p class="hero-num shrink-0 text-2xl font-semibold {{ $selected->amount_cents > 0 ? 'text-brand' : '' }}">{{ money($selected->amount_cents, sign: true) }}</p>
                    </div>

                    <div class="mt-4 space-y-3">
                        <label class="block">
                            <span class="mb-1.5 block text-xs font-medium text-ink-2">{{ __('Category') }}</span>
                            <select wire:change="setCategory({{ $selected->id }}, $event.target.value)" class="field">
                                <option value="0" @selected(! $selected->category_id)>❓ {{ __('Uncategorized') }}</option>
                                @foreach ($categories as $cat)
                                    <option value="{{ $cat->id }}" @selected($selected->category_id === $cat->id)>{{ $cat->icon }} {{ $cat->name }}</option>
                                @endforeach
                            </select>
                        </label>

                        @if ($selectedSubscription)
                            <div class="rounded-2xl bg-brand/10 p-4 ring-1 ring-brand/30">
                                <div class="flex items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <p class="text-xs font-medium uppercase tracking-wider text-brand">🔁 {{ __('Subscription') }}</p>
                                        <p class="mt-0.5 truncate text-sm font-semibold">{{ $selectedSubscription->label }}</p>
                                        <p class="text-[11px] text-ink-3">
                                            {{ $selectedSubscription->period === 'annual' ? __('Annual') : __('Monthly') }} · {{ $selectedSubscription->occurrences }}× · ≈ {{ money($selectedSubscription->monthlyCostCents()) }}{{ __('/month') }}
                                        </p>
                                    </div>
                                    <a href="{{ route('subscriptions') }}" wire:navigate class="chip shrink-0">{{ __('Manage') }} →</a>
                                </div>
                                <button type="button" wire:click="unmarkSubscription({{ $selected->id }})"
                                        class="mt-3 text-xs text-ink-3 underline decoration-dotted">{{ __('This is not a subscription') }}</button>
                            </div>
                        @elseif ($selected->amount_cents < 0 && $selected->merchant)
                            <form wire:submit="markAsSubscription({{ $selected->id }})" class="rounded-2xl bg-surface-2 p-4 ring-1 ring-white/10">
                                <p class="text-xs font-medium text-ink-2">{{ __('Mark as subscription') }}</p>
                                <p class="mt-0.5 text-[11px] text-ink-3">
                                    {{ __('All « :merchant » operations around :amount will be linked.', ['merchant' => Str::limit($selected->merchant, 24), 'amount' => money(abs($selected->amount_cents))]) }}
                                </p>
                                <div class="mt-3 flex gap-2">
                                    <input type="text" wire:model="subscriptionLabel" placeholder="{{ __('Name (e.g. Netflix)') }}" class="field min-w-0 flex-1" autocomplete="off">
                                    <select wire:model="subscriptionPeriod" class="field w-auto shrink-0">
                                        <option value="monthly">{{ __('Monthly') }}</option>
                                        <option value="annual">{{ __('Annual') }}</option>
                                    </select>
                                </div>
                                @error('subscriptionLabel') <p class="mt-1 text-xs text-rose-400">{{ $message }}</p> @enderror
                                <button type="submit" class="btn-primary mt-3 w-full">🔁 {{ __('It is a subscription') }}</button>
                            </form>
                        @endif

                        @if ($selected->source === 'manual')
                            <button type="button" wire:click="deleteManual({{ $selected->id }})" wire:confirm="{{ __('Delete this operation?') }}"
                                    class="w-full rounded-2xl bg-rose-400/10 py-2.5 text-sm font-semibold text-rose-300">{{ __('Delete this operation') }}</button>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
