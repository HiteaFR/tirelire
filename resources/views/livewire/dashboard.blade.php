<div>
    <x-topbar :month-date="$monthDate" :can-go-next="$canGoNext" :months="$months">
        <a href="{{ route('accounts') }}" wire:navigate aria-label="{{ __('Accounts and settings') }}"
           class="grid size-10 place-items-center rounded-full bg-surface text-ink-2 ring-1 ring-white/10">
            <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
        </a>
    </x-topbar>

    <div class="space-y-4">
        {{-- Patrimoine net : carte héro --}}
        <section class="relative overflow-hidden rounded-3xl bg-linear-to-br from-emerald-400 via-emerald-600 to-teal-900 p-5 text-white shadow-xl shadow-emerald-950/40">
            <div class="pointer-events-none absolute -right-16 -top-20 size-64 rounded-full bg-white/15 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-24 -left-10 size-56 rounded-full bg-teal-300/20 blur-3xl"></div>

            <div class="relative">
                <p class="text-xs font-medium uppercase tracking-wider text-white/70">
                    {{ $selectedAccount ? __('Balance :account', ['account' => $selectedAccount->name]) : __('Net worth') }}
                </p>
                <p class="hero-num mt-1 text-[2.6rem] font-semibold leading-none">{{ money($totalBalance) }}</p>
                <p class="mt-2 text-xs text-white/70">
                    @if ($balanceDate)
                        {{ $selectedAccount || $accounts->count() === 1
                            ? __('Balance on :date', ['date' => $balanceDate->isoFormat('ll')])
                            : __(':count accounts · balance on :date', ['count' => $accounts->count(), 'date' => $balanceDate->isoFormat('ll')]) }}
                    @elseif ($updatedAt)
                        {{ $selectedAccount || $accounts->count() === 1
                            ? __('Last operation on :date', ['date' => $updatedAt->isoFormat('ll')])
                            : __(':count accounts · last operation on :date', ['count' => $accounts->count(), 'date' => $updatedAt->isoFormat('ll')]) }}
                    @else
                        {{ __('No operation recorded') }}
                    @endif
                </p>

                @if ($accounts->count() > 1)
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        <button wire:click="selectAccount(null)"
                                class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold transition {{ ! $selectedAccount ? 'bg-white text-emerald-900' : 'bg-white/15 text-white' }}">
                            {{ __('All') }}
                        </button>
                        @foreach ($accounts as $acc)
                            <button wire:click="selectAccount({{ $acc->id }})" wire:key="acc-chip-{{ $acc->id }}"
                                    class="shrink-0 rounded-full px-3 py-1.5 text-xs font-semibold transition {{ $selectedAccount?->id === $acc->id ? 'bg-white text-emerald-900' : 'bg-white/15 text-white' }}">
                                {{ $acc->bank ?: $acc->name }}
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </section>

        {{-- Détail par compte : solde daté de sa dernière opération, ou de la fin du mois affiché s'il est passé --}}
        @if ($accounts->count() > 1 && ! $selectedAccount)
            <section class="card divide-y divide-white/5">
                @foreach ($accountRows as $row)
                    <button wire:click="selectAccount({{ $row['account']->id }})" wire:key="acc-row-{{ $row['account']->id }}"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left">
                        <span class="grid size-9 shrink-0 place-items-center rounded-full bg-surface-2 text-xs font-bold text-ink-2">
                            {{ mb_strtoupper(mb_substr($row['account']->bank ?: $row['account']->name, 0, 2)) }}
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $row['account']->name }}</p>
                            <p class="text-[11px] text-ink-3">
                                {{ $row['account']->bank ?: __('Bank not specified') }}
                                @if ($row['date']) · {{ __('on :date', ['date' => $row['date']->isoFormat('D MMM')]) }} @endif
                            </p>
                        </div>
                        <x-money :cents="$row['balance']" class="shrink-0 text-sm font-semibold" />
                    </button>
                @endforeach
            </section>
        @endif

        {{-- Alerte : abonnement prélevé après résiliation --}}
        @if ($resumedSubscriptions->isNotEmpty())
            <a href="{{ route('subscriptions') }}" wire:navigate
               class="flex items-center gap-3 rounded-2xl bg-amber-400/10 px-4 py-3 text-sm text-amber-200 ring-1 ring-amber-400/30">
                <span class="text-lg">🔁</span>
                <span class="flex-1">
                    {{ trans_choice(':names was charged after cancellation — please check.|:names were charged after cancellation — please check.', $resumedSubscriptions->count(), ['names' => $resumedSubscriptions->pluck('label')->join(', ', ' '.__('and').' ')]) }}
                </span>
                <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        @endif

        {{-- Alerte : relevé pas à jour --}}
        @if ($lastTransaction)
            @php $daysAgo = $lastTransaction->date->startOfDay()->diffInDays(now()->startOfDay()); @endphp
            @if ($daysAgo > 7)
                <a href="{{ route('import') }}" wire:navigate
                   class="flex items-center gap-3 rounded-2xl bg-amber-400/10 px-4 py-3 text-sm text-amber-200 ring-1 ring-amber-400/30">
                    <span class="text-lg">⚠️</span>
                    <span class="flex-1">{{ __('No operation for :days days — remember to import your statement.', ['days' => $daysAgo]) }}</span>
                    <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </a>
            @endif
        @else
            <a href="{{ route('import') }}" wire:navigate
               class="card block border border-dashed border-white/15 bg-transparent p-5 text-center text-sm text-ink-2">
                {{ __('No operation recorded — import a first statement') }}
            </a>
        @endif

        {{-- Taux d'épargne : prévu pour le mois en cours, réalisé pour un mois passé --}}
        @php
            $rate = $savingsRate;
            $ringColor = match (true) { $rate === null => '#3f3f46', $rate < 0 => '#fb7185', $rate < 10 => '#fbbf24', default => '#34d399' };
            $ringLen = 2 * M_PI * 26;
            $ringFill = $rate === null ? 0 : min(100, max(0, $rate)) / 100 * $ringLen;
            $monthName = $monthDate->isoFormat('MMMM');
        @endphp

        @if ($forecast && ! $lastTransaction)
            {{-- Aucune opération : pas de prévision (elle annoncerait un découvert à 0), l'invitation à importer plus haut suffit --}}
        @elseif ($forecast)
            {{-- Mois en cours : où en sera le solde à la fin du mois --}}
            @php
                $f = $forecast;
                $endOfMonth = $monthDate->endOfMonth();
                $isLastDay = $f['remaining']['days'] === 0;
            @endphp
            <section class="card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wider text-ink-3">{{ __('Expected balance end of :month', ['month' => $monthName]) }}</p>
                        <p class="hero-num mt-1 text-3xl font-semibold leading-none {{ $f['month_end_balance'] < 0 ? 'text-rose-400' : 'text-brand' }}">{{ money($f['month_end_balance'], sign: true) }}</p>
                        <p class="mt-1.5 text-xs text-ink-2">
                            {{ __('Expected result for the month') }} <x-money :cents="$f['month_result']" sign class="font-semibold" />
                        </p>
                    </div>
                    <div class="relative grid size-16 shrink-0 place-items-center" title="{{ __('Expected savings rate for the month') }}">
                        <svg class="absolute inset-0 -rotate-90" viewBox="0 0 64 64" aria-hidden="true">
                            <circle cx="32" cy="32" r="26" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="6"/>
                            <circle cx="32" cy="32" r="26" fill="none" stroke="{{ $ringColor }}" stroke-width="6" stroke-linecap="round"
                                    stroke-dasharray="{{ $ringFill }} {{ $ringLen }}"/>
                        </svg>
                        <span class="num text-sm font-bold">{{ $rate === null ? '—' : $rate.'%' }}</span>
                    </div>
                </div>

                {{-- Verdict : ce qu'on peut réellement mettre de côté, découvert compris --}}
                @if ($f['safe_to_save'] > 0)
                    <div class="mt-4 rounded-2xl bg-emerald-400/10 px-4 py-3 text-sm text-emerald-100 ring-1 ring-emerald-400/30">
                        {{ __('You can put') }} <span class="num font-semibold">{{ money($f['safe_to_save']) }}</span> {{ __('aside without going overdrawn until :date.', ['date' => $f['safe_until']->isoFormat('D MMMM')]) }}
                        <span class="mt-0.5 block text-[11px] text-emerald-200/70">
                            {{ __('Expected low point: :amount on :date', ['amount' => money($f['low_point']['balance']), 'date' => $f['low_point']['date']->isoFormat('D MMM')]) }}
                        </span>
                    </div>
                @else
                    <div class="mt-4 rounded-2xl bg-rose-400/10 px-4 py-3 text-sm text-rose-100 ring-1 ring-rose-400/30">
                        {{ __('Nothing to put aside: overdraft expected down to') }}
                        <span class="num font-semibold">{{ money($f['low_point']['balance']) }}</span>
                        {{ __('on :date.', ['date' => $f['low_point']['date']->isoFormat('D MMMM')]) }}
                        <span class="mt-0.5 block text-[11px] text-rose-200/70">
                            @if ($f['month_end_balance'] >= 0)
                                {{ __('The balance goes back into the red before the next salary.') }}
                            @elseif ($f['back_to_positive'])
                                {{ __('Back to positive estimated end of :month, at this pace.', ['month' => $f['back_to_positive']->isoFormat('MMMM YYYY')]) }}
                            @else
                                {{ __('No return to positive within 12 months at this pace.') }}
                            @endif
                        </span>
                    </div>
                @endif

                {{-- Rythme du mois : dépenses courantes réelles face à la moyenne, à date --}}
                @if ($f['pace']['days'] > 0 && $f['pace']['expected'] > 0)
                    @php
                        $pace = $f['pace'];
                        $isAhead = $pace['ahead'] >= 0;
                        $paceFill = min(100, $pace['spent'] / $pace['expected'] * 100);
                    @endphp
                    <div class="mt-4 rounded-2xl bg-white/[0.03] px-4 py-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-medium">{{ __('Month pace') }}</p>
                            <p class="num shrink-0 text-sm font-semibold {{ $isAhead ? 'text-brand' : 'text-rose-400' }}">
                                {{ $isAhead ? __(':amount ahead', ['amount' => money(abs($pace['ahead']))]) : __(':amount behind', ['amount' => money(abs($pace['ahead']))]) }}
                            </p>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-white/5">
                            <div class="h-full rounded-full {{ $isAhead ? 'bg-brand' : 'bg-rose-400' }}" style="width: {{ $paceFill }}%"></div>
                        </div>
                        <p class="mt-2 text-[11px] text-ink-3">
                            {{ __('Everyday spending:') }} <span class="num font-semibold text-ink">{{ money($pace['spent']) }}</span>
                            {{ __('in :days d, for', ['days' => $pace['days']]) }} <span class="num">{{ money($pace['expected']) }}</span> {{ __('expected at your average pace') }}
                            ({{ money($f['everyday_daily']) }}{{ __('/d') }})
                        </p>
                    </div>
                @endif

                {{-- Déroulé du mois : du solde réel du 1er au solde estimé du dernier jour --}}
                <dl class="mt-4 divide-y divide-white/5 border-t border-white/5 text-sm">
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Balance on') }} 1<sup>{{ __('st') }}</sup> {{ $monthName }}</dt>
                        <dd class="shrink-0"><x-money :cents="$f['start_balance']" tone="neutral" class="font-semibold" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Income received') }}</dt>
                        <dd class="shrink-0"><x-money :cents="$f['realized']['income']" sign /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Past expenses') }}</dt>
                        <dd class="shrink-0"><x-money :cents="-$f['realized']['expense']" /></dd>
                    </div>
                    @if ($f['realized']['transfers'] !== 0)
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="min-w-0 text-ink-2">{{ __('Transfers & savings') }}</dt>
                            <dd class="shrink-0"><x-money :cents="$f['realized']['transfers']" sign /></dd>
                        </div>
                    @endif
                    <div class="flex items-center justify-between gap-3 bg-white/[0.03] py-2.5">
                        <dt class="min-w-0 font-semibold">{{ __('Balance on :date', ['date' => $f['anchor']->isoFormat('D MMM')]) }}
                            <span class="block text-[11px] font-normal text-ink-3">{{ __('last imported operation') }}</span></dt>
                        <dd class="shrink-0"><x-money :cents="$f['balance']" class="font-semibold" /></dd>
                    </div>
                    @if (! $isLastDay)
                        @if ($f['remaining']['income'] > 0)
                            <div class="flex items-center justify-between gap-3 py-2">
                                <dt class="min-w-0 text-ink-2">{{ __('Expected income') }}</dt>
                                <dd class="shrink-0"><x-money :cents="$f['remaining']['income']" sign /></dd>
                            </div>
                        @endif
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="min-w-0 text-ink-2">{{ __('Upcoming charges') }}
                                <span class="block text-[11px] text-ink-3">{{ trans_choice('{0} :count debits until :date|{1} :count debit until :date|[2,*] :count debits until :date', $f['remaining']['charges_count'], ['date' => $endOfMonth->isoFormat('D MMM')]) }}</span></dt>
                            <dd class="shrink-0"><x-money :cents="-$f['remaining']['charges']" /></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 py-2">
                            <dt class="min-w-0 text-ink-2">{{ __('Estimated everyday spending') }}
                                <span class="block text-[11px] text-ink-3">{{ money($f['everyday_daily']) }}{{ __('/d') }} × {{ $f['remaining']['days'] }} {{ __('d') }} · {{ __('average') }} {{ $f['everyday_window']['from']->isoFormat('MMM') }}–{{ $f['everyday_window']['to']->isoFormat('MMM') }}</span></dt>
                            <dd class="shrink-0"><x-money :cents="-$f['remaining']['everyday']" /></dd>
                        </div>
                        <div class="flex items-center justify-between gap-3 bg-white/[0.03] py-2.5">
                            <dt class="min-w-0 font-semibold">{{ __('Estimated end of month') }}</dt>
                            <dd class="shrink-0"><x-money :cents="$f['month_end_balance']" class="font-semibold" /></dd>
                        </div>
                    @endif
                </dl>
            </section>

            {{-- Projection : régime mensuel et soldes de fin de mois à venir --}}
            @php
                $nextMonth = $monthDate->addMonth();
                $ends = collect($f['month_ends']);
                $tiles = $ends->slice(1, 3)->push($ends->last())->unique(fn ($m) => $m['month']->format('Y-m'))->values();
                $upcoming = collect($f['upcoming']);
            @endphp
            <section class="card p-5">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-semibold">{{ __('Projection') }}</p>
                    <a href="{{ route('subscriptions') }}" wire:navigate class="text-xs font-medium text-brand">{{ __('Subscriptions') }} →</a>
                </div>
                <p class="mt-0.5 text-[11px] text-ink-3">{{ __('Known charges on their date + everyday spending at the current pace') }}</p>

                <dl class="mt-3 divide-y divide-white/5 text-sm">
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Expected income in :month', ['month' => $nextMonth->isoFormat('MMMM')]) }}</dt>
                        <dd class="shrink-0"><x-money :cents="$f['monthly']['income']" sign /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Charges') }}</dt>
                        <dd class="shrink-0"><x-money :cents="-$f['monthly']['charges']" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <dt class="min-w-0 text-ink-2">{{ __('Everyday spending') }}</dt>
                        <dd class="shrink-0"><x-money :cents="-$f['monthly']['everyday']" /></dd>
                    </div>
                    <div class="flex items-center justify-between gap-3 bg-white/[0.03] py-2.5">
                        <dt class="min-w-0 font-semibold">{{ __('Monthly margin') }}</dt>
                        <dd class="shrink-0 whitespace-nowrap"><x-money :cents="$f['monthly']['margin']" sign class="font-semibold" /><span class="text-xs text-ink-3">{{ __('/month') }}</span></dd>
                    </div>
                </dl>

                @if ($tiles->isNotEmpty())
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        @foreach ($tiles as $tile)
                            <div class="rounded-2xl bg-white/[0.03] px-3 py-2.5" wire:key="proj-{{ $tile['month']->format('Y-m') }}">
                                <p class="text-[11px] text-ink-3">{{ __('End of :month', ['month' => $tile['month']->isoFormat($tile['month']->year === $monthDate->year ? 'MMMM' : 'MMM YYYY')]) }}</p>
                                <x-money :cents="$tile['balance']" class="mt-0.5 block text-base font-semibold" />
                            </div>
                        @endforeach
                    </div>
                @endif

                @if ($upcoming->isNotEmpty())
                    <details class="group mt-4 border-t border-white/5 pt-3">
                        <summary class="flex cursor-pointer list-none items-center justify-between text-sm font-medium">
                            <span>{{ __('Next charges') }} <span class="text-ink-3">({{ $upcoming->count() }})</span></span>
                            <svg class="size-4 text-ink-3 transition group-open:rotate-180" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                        </summary>
                        <div class="mt-2 divide-y divide-white/5">
                            @foreach ($upcoming as $i => $event)
                                <div class="flex items-center gap-3 py-2" wire:key="up-{{ $i }}">
                                    <span class="w-12 shrink-0 text-[11px] font-medium text-ink-3">{{ $event['date']->isoFormat('D MMM') }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm">{{ $event['label'] }}</p>
                                        <p class="text-[11px] text-ink-3">
                                            {{ __('estimated balance :amount', ['amount' => money($event['balance'])]) }}
                                            @if ($event['late']) · {{ __('late') }} @endif
                                            @if ($event['to_cancel']) · <span class="text-amber-300">{{ __('to cancel') }}</span> @endif
                                        </p>
                                    </div>
                                    <x-money :cents="$event['amount']" sign class="shrink-0 text-sm font-semibold" />
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endif
            </section>
        @else
            {{-- Mois passé : bilan réalisé --}}
            @php
                $net = $totals['net'];
                $income = $totals['income'];
                $fixed = $kpis['fixed_expense'];
                $variable = $kpis['variable_expense'];
                $base = max($income, $totals['expense'], 1);
                $fixedPct = $fixed / $base * 100;
                $variablePct = $variable / $base * 100;
                $savingsPct = $income > 0 ? max(0, $net) / $base * 100 : 0;
            @endphp
            <section class="card p-5">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wider text-ink-3">{{ __('Review of :month', ['month' => $monthName]) }}</p>
                        <p class="hero-num mt-1 text-3xl font-semibold leading-none {{ $net < 0 ? 'text-rose-400' : 'text-brand' }}">{{ money($net, sign: true) }}</p>
                        <p class="mt-1.5 text-xs text-ink-2">
                            @if ($income === 0 && $totals['expense'] === 0)
                                {{ __('No flow that month') }}
                            @else
                                {{ $net < 0 ? __('Deficit for the month') : __('Saved over the month') }} · {{ __('balance') }}
                                <x-money :cents="$monthStartBalance" class="font-semibold" /> →
                                <x-money :cents="$totalBalance" class="font-semibold" />
                            @endif
                        </p>
                    </div>
                    <div class="relative grid size-16 shrink-0 place-items-center" title="{{ __('Savings rate') }}">
                        <svg class="absolute inset-0 -rotate-90" viewBox="0 0 64 64" aria-hidden="true">
                            <circle cx="32" cy="32" r="26" fill="none" stroke="rgba(255,255,255,0.08)" stroke-width="6"/>
                            <circle cx="32" cy="32" r="26" fill="none" stroke="{{ $ringColor }}" stroke-width="6" stroke-linecap="round"
                                    stroke-dasharray="{{ $ringFill }} {{ $ringLen }}"/>
                        </svg>
                        <span class="num text-sm font-bold">{{ $rate === null ? '—' : $rate.'%' }}</span>
                    </div>
                </div>

                {{-- Répartition du revenu : fixes / variables / épargne --}}
                <div class="mt-4">
                    <div class="mb-2 flex items-center justify-between text-[11px] text-ink-3">
                        <span>{{ __('Income') }} <span class="num font-semibold text-ink">{{ money($income) }}</span></span>
                        <span>{{ __('Expenses') }} <span class="num font-semibold text-ink">{{ money($totals['expense']) }}</span></span>
                    </div>
                    <div class="flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-white/5">
                        <div class="h-full rounded-full bg-indigo-400" style="width: {{ $fixedPct }}%"></div>
                        <div class="h-full rounded-full bg-orange-400" style="width: {{ $variablePct }}%"></div>
                        <div class="h-full rounded-full bg-brand" style="width: {{ $savingsPct }}%"></div>
                    </div>
                    <div class="mt-2.5 grid grid-cols-3 gap-2 text-[11px]">
                        <div>
                            <p class="flex items-center gap-1.5 text-ink-3"><span class="size-2 rounded-full bg-indigo-400"></span>{{ __('Fixed') }}</p>
                            <p class="num mt-0.5 font-semibold">{{ money($fixed) }}</p>
                        </div>
                        <div>
                            <p class="flex items-center gap-1.5 text-ink-3"><span class="size-2 rounded-full bg-orange-400"></span>{{ __('Variable') }}</p>
                            <p class="num mt-0.5 font-semibold">{{ money($variable) }}</p>
                        </div>
                        <div>
                            <p class="flex items-center gap-1.5 text-ink-3"><span class="size-2 rounded-full bg-brand"></span>{{ __('Savings') }}</p>
                            <p class="num mt-0.5 font-semibold {{ $net < 0 ? 'text-rose-400' : '' }}">{{ money($net, sign: true) }}</p>
                        </div>
                    </div>
                </div>
            </section>
        @endif

        {{-- Indicateurs secondaires --}}
        <section class="grid grid-cols-2 gap-3">
            <div class="card p-4">
                <p class="text-xs text-ink-3">{{ __('Variable spending / day') }}</p>
                <p class="num mt-1 text-xl font-semibold">{{ money($kpis['avg_daily_variable']) }}</p>
                <p class="mt-0.5 text-[11px] text-ink-3">{{ trans_choice('{0} :count operations this month|{1} :count operation this month|[2,*] :count operations this month', $kpis['count']) }}</p>
            </div>
            <div class="card p-4">
                <p class="text-xs text-ink-3">{{ __('Biggest expense') }}</p>
                @if ($kpis['biggest_expense'])
                    <p class="num mt-1 text-xl font-semibold">{{ money(abs($kpis['biggest_expense']->amount_cents)) }}</p>
                    <p class="mt-0.5 truncate text-[11px] text-ink-3">{{ $kpis['biggest_expense']->category?->icon }} {{ $kpis['biggest_expense']->merchant ?: Str::limit($kpis['biggest_expense']->label, 30) }}</p>
                @else
                    <p class="mt-1 text-xl font-semibold text-ink-3">—</p>
                @endif
            </div>
        </section>

        {{-- Historique 6 mois : tap sur un mois pour le sélectionner --}}
        @php
            $maxVal = max(1, collect($series)->max(fn ($s) => max($s['income'], $s['expense'])));
            $inRange = collect($series)->contains(fn ($s) => $s['month']->isSameMonth($monthDate));
        @endphp
        <section class="card p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold">{{ __('Last 6 months') }}</p>
                <div class="flex items-center gap-3 text-[11px] text-ink-3">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-chart-in"></span>{{ __('Income') }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-chart-out"></span>{{ __('Expenses') }}</span>
                </div>
            </div>
            <div class="mt-4 flex items-end gap-1" style="height: 140px">
                @foreach ($series as $s)
                    @php $selected = $s['month']->isSameMonth($monthDate); @endphp
                    <button type="button" wire:click="selectMonth('{{ $s['month']->format('Y-m') }}')" wire:key="bar-{{ $s['month']->format('Y-m') }}"
                            class="group flex h-full flex-1 flex-col items-center rounded-xl pt-1 transition {{ $selected ? 'bg-white/5' : '' }} {{ ! $inRange || $selected ? '' : 'opacity-50' }}">
                        <span class="num mb-1 h-4 text-[10px] font-semibold {{ $selected ? ($s['net'] < 0 ? 'text-rose-400' : 'text-brand') : 'invisible' }}">
                            {{ $s['net'] === 0 ? '' : money($s['net'], sign: true, decimals: 0, symbol: false) }}
                        </span>
                        <span class="flex w-full flex-1 items-end justify-center gap-0.5 px-1">
                            <span class="w-full max-w-3 rounded-t-[3px] bg-chart-in" style="height: {{ max(2, round($s['income'] / $maxVal * 100)) }}%"></span>
                            <span class="w-full max-w-3 rounded-t-[3px] bg-chart-out" style="height: {{ max(2, round($s['expense'] / $maxVal * 100)) }}%"></span>
                        </span>
                        <span class="mt-2 text-[10px] font-medium capitalize {{ $selected ? 'text-ink' : 'text-ink-3' }}">{{ $s['month']->isoFormat('MMM') }}</span>
                    </button>
                @endforeach
            </div>
        </section>

        {{-- Dépenses par catégorie --}}
        @php
            $totalSpent = max(1, $byCategory->sum('spent'));
            $shares = $byCategory->take(6);
            $otherSpent = $byCategory->skip(6)->sum('spent');
        @endphp
        <section class="card p-5">
            <div class="flex items-center justify-between">
                <p class="text-sm font-semibold">{{ __('Spending by category') }}</p>
                <a href="{{ route('budgets') }}" wire:navigate class="text-xs font-medium text-brand">{{ __('Budgets') }} →</a>
            </div>

            @if ($byCategory->isNotEmpty())
                <div class="mt-3 flex h-2.5 gap-0.5 overflow-hidden rounded-full bg-white/5">
                    @foreach ($shares as $row)
                        <div class="h-full rounded-full" wire:key="share-{{ $row->category?->id ?? 0 }}"
                             style="width: {{ $row->spent / $totalSpent * 100 }}%; background: {{ $row->category?->color ?? '#71717a' }}"></div>
                    @endforeach
                    @if ($otherSpent > 0)
                        <div class="h-full rounded-full bg-zinc-600" style="width: {{ $otherSpent / $totalSpent * 100 }}%"></div>
                    @endif
                </div>
            @endif

            <div class="mt-3 divide-y divide-white/5">
                @forelse ($byCategory as $row)
                    @php
                        $cat = $row->category;
                        $over = $row->budget !== null && $row->spent > $row->budget;
                        $share = round($row->spent / $totalSpent * 100);
                    @endphp
                    <a href="{{ route('transactions', ['month' => $month, 'category' => $cat?->id ?? 0, 'account' => $account]) }}" wire:navigate
                       wire:key="cat-{{ $cat?->id ?? 0 }}" class="flex items-center gap-3 py-2.5">
                        <x-category-icon :category="$cat" size="sm" />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ $cat?->name ?? __('Uncategorized') }}</p>
                            <p class="text-[11px] text-ink-3">
                                {{ __(':percent% of spending', ['percent' => $share]) }}
                                @if ($row->budget !== null)
                                    · <span class="{{ $over ? 'font-semibold text-rose-400' : '' }}">{{ $over ? __('budget exceeded') : __('budget :amount', ['amount' => money($row->budget)]) }}</span>
                                @endif
                            </p>
                        </div>
                        <span class="num shrink-0 text-sm font-semibold {{ $over ? 'text-rose-400' : '' }}">{{ money($row->spent) }}</span>
                    </a>
                @empty
                    <p class="py-4 text-center text-sm text-ink-3">{{ __('No spending this month.') }}</p>
                @endforelse
            </div>
        </section>

        {{-- Dernières opérations --}}
        @if ($recent->isNotEmpty())
            @php $daysAgo = $lastTransaction->date->startOfDay()->diffInDays(now()->startOfDay()); @endphp
            <section class="card p-5">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold">{{ __('Latest operations') }}</p>
                        <p class="text-[11px] text-ink-3">
                            @if ($daysAgo === 0) {{ __('Last operation today') }} @elseif ($daysAgo === 1) {{ __('Last operation yesterday') }} @else {{ __('Last operation :days d ago', ['days' => $daysAgo]) }} @endif
                        </p>
                    </div>
                    <a href="{{ route('transactions', ['month' => $lastTransaction->date->format('Y-m'), 'account' => $account]) }}" wire:navigate
                       class="text-xs font-medium text-brand">{{ __('See all') }} →</a>
                </div>
                <div class="mt-2 divide-y divide-white/5">
                    @foreach ($recent as $tx)
                        <div class="flex items-center gap-3 py-2.5" wire:key="recent-{{ $tx->id }}">
                            <x-category-icon :category="$tx->category" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ $tx->merchant ?: Str::limit($tx->label, 40) }}</p>
                                <p class="text-[11px] text-ink-3">
                                    {{ $tx->date->isoFormat('D MMM') }}
                                    · {{ $tx->category?->name ?? __('Uncategorized') }}
                                    @if ($accounts->count() > 1 && ! $account) · {{ $tx->account->bank ?: $tx->account->name }} @endif
                                </p>
                            </div>
                            <span class="num shrink-0 text-sm font-semibold {{ $tx->amount_cents > 0 ? 'text-brand' : '' }}">{{ money($tx->amount_cents, sign: true) }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</div>
