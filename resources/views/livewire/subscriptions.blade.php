<div>
    <x-topbar :title="__('Subscriptions')">
        <button wire:click="redetect" wire:loading.attr="disabled" class="chip h-10 px-4">
            <span wire:loading.remove wire:target="redetect">↻ {{ __('Detect again') }}</span>
            <span wire:loading wire:target="redetect">…</span>
        </button>
    </x-topbar>

    <div class="space-y-4">
        {{-- Prélevés après résiliation : reprise volontaire ou résiliation ratée ? --}}
        @foreach ($resumed as $sub)
            <section class="rounded-2xl bg-amber-400/10 px-4 py-3 text-sm text-amber-100 ring-1 ring-amber-400/30" wire:key="resumed-{{ $sub->id }}">
                <p>
                    <span class="font-semibold">{{ $sub->label }}</span> {{ __('was charged on :date', ['date' => $sub->resumed_on->isoFormat('ll')]) }}
                    (<span class="num">{{ money($sub->avg_amount_cents) }}</span>) {{ __('although it was cancelled.') }}
                </p>
                <div class="mt-2.5 flex flex-wrap gap-2">
                    <button wire:click="confirmResumed({{ $sub->id }})" class="chip">{{ __('I resumed it') }}</button>
                    <button wire:click="keepCancelled({{ $sub->id }})" class="chip text-amber-300">{{ __('Still cancelled') }}</button>
                </div>
            </section>
        @endforeach

        <section class="grid grid-cols-2 gap-3">
            <div class="card p-4">
                <p class="text-xs text-ink-3">{{ __('Recurring total') }}</p>
                <p class="num mt-1 text-2xl font-semibold leading-none">{{ money($monthlyTotal) }}</p>
                <p class="num mt-1 text-[11px] text-ink-3">{{ __('per month') }} · {{ money($monthlyTotal * 12, decimals: 0) }}{{ __('/year') }}</p>
            </div>
            <div class="card p-4 {{ $toCancelSavings > 0 ? 'ring-1 ring-brand/40' : '' }}">
                <p class="text-xs text-ink-3">{{ __('Savings if cancelled') }}</p>
                <p class="num mt-1 text-2xl font-semibold leading-none text-brand">{{ money($toCancelSavings) }}</p>
                <p class="num mt-1 text-[11px] text-ink-3">{{ __('per month') }} · {{ money($toCancelSavings * 12, decimals: 0) }}{{ __('/year') }}</p>
            </div>
        </section>

        <p class="px-1 text-xs text-ink-3">
            {{ __('Detected from your operations. Mark « to cancel » what you want to stop, type a name to rename it. One is missing? Open the operation in Operations and mark it as a subscription.') }}
        </p>

        <div class="space-y-2">
            @forelse ($active as $sub)
                <div class="card p-3 {{ $sub->status === 'to_cancel' ? 'ring-1 ring-amber-400/50' : '' }}" wire:key="sub-{{ $sub->id }}">
                    <div class="flex items-center gap-3">
                        <x-category-icon :category="$sub->category" size="sm" />
                        <div class="min-w-0 flex-1">
                            {{-- Le nom est modifiable en place : « Paypal » devient « Netflix » --}}
                            <input type="text" value="{{ $sub->label }}" aria-label="{{ __('Subscription name') }}" maxlength="100"
                                   wire:change="rename({{ $sub->id }}, $event.target.value)"
                                   class="w-full truncate rounded-md bg-transparent text-sm font-medium focus:bg-surface-2 focus:px-1.5 focus:outline-none">
                            <p class="truncate text-[11px] text-ink-3">
                                @if ($sub->label !== \App\Models\Subscription::prettyLabel($sub->merchant))
                                    {{ \App\Models\Subscription::prettyLabel($sub->merchant) }} ·
                                @endif
                                {{ $sub->period === 'annual' ? __('Annual') : __('Monthly') }} · {{ $sub->occurrences }}× · {{ $sub->last_date->isoFormat('D MMM') }}
                            </p>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="num text-sm font-semibold">{{ money($sub->avg_amount_cents) }}</p>
                            @if ($sub->period === 'annual')
                                <p class="text-[11px] text-ink-3">≈ {{ money($sub->monthlyCostCents()) }}{{ __('/month') }}</p>
                            @endif
                        </div>
                    </div>
                    <div class="mt-2.5 flex gap-1.5">
                        @if ($sub->status === 'to_cancel')
                            <span class="chip bg-amber-400/15 text-amber-300 shadow-none">📌 {{ __('To cancel') }}</span>
                            <button wire:click="setStatus({{ $sub->id }}, 'cancelled')" class="chip">✓ {{ __('Cancelled!') }}</button>
                            <button wire:click="setStatus({{ $sub->id }}, 'active')" class="chip">{{ __('Keep') }}</button>
                        @else
                            <button wire:click="setStatus({{ $sub->id }}, 'to_cancel')" class="chip text-amber-300">{{ __('Cancel it?') }}</button>
                            <button wire:click="setStatus({{ $sub->id }}, 'ignored')" class="chip">{{ __('Not a sub') }}</button>
                        @endif
                    </div>
                </div>
            @empty
                <div class="card p-8 text-center">
                    <p class="text-3xl">🔎</p>
                    <p class="mt-2 text-sm text-ink-2">{{ __('Nothing detected yet — import a CSV statement first.') }}</p>
                </div>
            @endforelse
        </div>

        @if ($archived->isNotEmpty())
            <details class="card p-4">
                <summary class="cursor-pointer text-sm font-medium text-ink-2">{{ __('Cancelled / ignored (:count)', ['count' => $archived->count()]) }}</summary>
                <div class="mt-3 divide-y divide-white/5">
                    @foreach ($archived as $sub)
                        <div class="flex items-center justify-between py-2 text-sm text-ink-3" wire:key="arch-{{ $sub->id }}">
                            <span class="truncate">{{ $sub->status === 'cancelled' ? '✅' : '🚫' }} {{ $sub->label }}</span>
                            <button wire:click="setStatus({{ $sub->id }}, 'active')" class="text-xs text-brand">{{ __('Reactivate') }}</button>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
</div>
