<div>
    <x-topbar :month-date="$monthDate" :can-go-next="$canGoNext" :months="$months" />

    <div class="space-y-4">
        @php $pct = $totalBudget > 0 ? min(100, round($totalSpent / $totalBudget * 100)) : 0; $over = $totalSpent > $totalBudget; @endphp
        <section class="card p-5">
            <p class="text-xs font-medium uppercase tracking-wider text-ink-3">{{ __('Budgets') }}</p>
            @if ($totalBudget > 0)
                <div class="mt-1 flex items-baseline justify-between gap-3">
                    <p class="hero-num text-3xl font-semibold leading-none {{ $over ? 'text-rose-400' : '' }}">{{ money($totalSpent) }}</p>
                    <p class="num text-sm text-ink-2">{{ __('of :amount', ['amount' => money($totalBudget)]) }}</p>
                </div>
                <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-white/5">
                    <div class="h-full rounded-full {{ $over ? 'bg-rose-400' : ($pct >= 80 ? 'bg-amber-400' : 'bg-brand') }}" style="width: {{ $pct }}%"></div>
                </div>
                <p class="mt-2 text-xs text-ink-2">
                    @if ($over)
                        {{ __('Overspent by') }} <span class="num font-semibold text-rose-400">{{ money($totalSpent - $totalBudget) }}</span> {{ __('on budgeted categories.') }}
                    @else
                        {{ __('There is') }} <span class="num font-semibold text-brand">{{ money($totalBudget - $totalSpent) }}</span> {{ __('left on budgeted categories.') }}
                    @endif
                </p>
            @else
                <p class="mt-1 text-sm text-ink-2">
                    {{ __('Set a cap per category. The average of the previous 3 months is shown to help you: start by matching it, then tighten gradually.') }}
                </p>
            @endif
        </section>

        <div class="space-y-2">
            @foreach ($categories as $cat)
                @php
                    $spent = $spentByCategory->get($cat->id)?->spent ?? 0;
                    $budget = $budgets->get($cat->id);
                    $avg = (int) round($averages[$cat->id] ?? 0);
                    $catOver = $budget !== null && $spent > $budget;
                    $catPct = $budget > 0 ? min(100, round($spent / $budget * 100)) : ($spent > 0 ? 100 : 0);
                @endphp
                <div class="card p-3" wire:key="budget-{{ $cat->id }}">
                    <div class="flex items-center gap-3">
                        <x-category-icon :category="$cat" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $cat->name }}</p>
                            <p class="text-[11px] text-ink-3">
                                <span class="num {{ $catOver ? 'font-semibold text-rose-400' : '' }}">{{ money($spent) }}</span> {{ __('spent') }}
                            </p>
                            @if ($avg > 0)
                                <p class="num text-[11px] text-ink-3">{{ __('3-month avg. :amount', ['amount' => money($avg)]) }}</p>
                            @endif
                        </div>
                        <label class="flex items-center gap-1 rounded-xl bg-surface-2 px-2.5 py-1.5 ring-1 ring-white/10 focus-within:ring-brand">
                            <input type="text" inputmode="decimal" placeholder="—" aria-label="{{ __('Budget :category', ['category' => $cat->name]) }}"
                                   wire:model="amounts.{{ $cat->id }}" wire:change="saveBudget({{ $cat->id }})"
                                   class="num w-16 bg-transparent text-right text-sm focus:outline-none">
                            <span class="text-xs text-ink-3">{{ config('finance.currency_symbol') }}</span>
                        </label>
                    </div>
                    @if ($budget !== null)
                        <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-white/5">
                            <div class="h-full rounded-full {{ $catOver ? 'bg-rose-400' : ($catPct >= 80 ? 'bg-amber-400' : 'bg-brand') }}" style="width: {{ $catPct }}%"></div>
                        </div>
                        @if ($budget === 0)
                            <p class="mt-1 text-[10px] {{ $spent > 0 ? 'text-rose-400' : 'text-ink-3' }}">
                                {{ __('Goal: zero spending') }}{{ $spent > 0 ? ' — '.__('missed this month') : ' ✓' }}
                            </p>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</div>
